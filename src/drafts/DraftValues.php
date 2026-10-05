<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\Entry;
use craft\fields\Assets;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchApplied;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchFields;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoState;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\Layouts;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\seo\CraftSeoWriter;
use nineteenninetyfour\ghostwriter\seo\MetaContexts;
use nineteenninetyfour\ghostwriter\suggest\CraftSeoFields;

/**
 * What a session's draft puts into an entry, worked out with no side
 * effects: the entry's data in core's shape, its title, and what the
 * draft left for a person. Applier writes it into a Craft draft; the
 * Preview tab renders it unsaved. Both call this, so the preview shows
 * exactly what "Use this draft" would put in.
 *
 * With `$readOnly`, nothing at all is written: a striped placeholder is
 * used only where one is already in the field's volume (the first
 * "Use this draft" makes it), and is left out otherwise.
 */
class DraftValues
{
    public function __construct(
        private SchemaReader $reader = new SchemaReader(),
        private ?Layouts $layouts = null,
    ) {
        $this->layouts ??= Plugin::getInstance()->layouts;
    }

    /**
     * @param Entry $entry The entry as the form holds it: a draft, or the entry itself.
     * @return array{
     *     title: string,
     *     data: array<string, mixed>,
     *     schema: array<int, array<string, mixed>>,
     *     model: Schema,
     *     existing: array<int, int>,
     *     notes: array<int, string>,
     *     built: BuiltEntry,
     *     housePlaces: array<int, mixed>,
     *     placed: array<int, string>,
     *     editing: bool,
     *     search: ?SearchApplied,
     * }
     */
    public function for(Session $session, ContentType $type, Entry $entry, bool $readOnly = false, ?string $plan = null): array
    {
        if ($session->draft === null) {
            throw new InvalidArgumentException('There is no draft yet.');
        }

        $draft = Draft::parse($session->draft);
        $entryType = $entry->getType();
        $schema = $this->reader->read($entryType);
        $model = Schema::fromSpecs($schema);

        // The chosen layout (or the one asked for): the draft's own words,
        // arranged. With the writer's layout chosen this is the draft.
        // Without the links chosen for fields it doesn't hold (`gw_links`): they aren't a field to build.
        $words = (new DraftLayouts())->draftData($session, $model, $plan);
        unset($words[MarkerResolver::CHOSEN_LINKS]);
        $draft = new Draft($words, $draft->raw);

        $editing = $session->isEditing();
        $existing = [];
        $pattern = null;

        if ($editing) {
            // Editing an entry: only the writing changes. Its images, links,
            // settings and blocks come from the entry as it stands in this
            // draft, not from what this kind of entry usually has.
            $original = (new EntryReader())->read($entry, $schema);
            $built = $this->layouts->build($draft->data, $model);
            $data = (new EntryMerger())->merge($built->data, $original, $schema);
            $notes = [];
            $existing = $this->blockIds($original);
        } else {
            $pattern = $this->layouts->pattern($type->group, $model, $entryType->handle, $type->where, $type->examples);
            $built = $this->layouts->build($draft->data, $model, $pattern, $type->defaults);
            $data = $built->data;
            $notes = $built->notes;
        }

        // What the model entries agree on place by place: settings and links
        // in the same position, nested items such as breadcrumbs, and the
        // markup around rich text. A new entry only; an existing one keeps
        // its own.
        $housePlaces = [];
        $placed = [];

        if ($pattern !== null) {
            $house = $this->layouts->houseStyle($data, $model, $pattern->house, (int) $entry->getCanonicalId(), (string) ($data['title'] ?? $draft->title()));
            $data = $house->data;
            $housePlaces = $house->toFill;

            if ($note = $house->note()) {
                $notes[] = $note;
            }
        }

        // Links chosen from the preview for fields the draft doesn't hold (a
        // button's link): kept in the session's draft by hint, put in
        // wherever the house style's sentinel for it turned up. Craft's link
        // fields take the entry's address.
        if (($chosen = MarkerResolver::chosenLinks(Draft::parse($session->draft)->data)) !== []) {
            $data = MarkerResolver::withChosenLinks($data, $chosen, references: false);
            $notes = self::withoutChosen($notes, $chosen, $data);
            $housePlaces = self::withoutChosen($housePlaces, $chosen, $data, places: true);
        }

        // An image already in the entry's own image fields, chosen with the
        // image button or uploaded while the piece was being written, is
        // kept where the draft has none, rather than taken out or covered
        // by a placeholder.
        if (!$editing) {
            $data = $this->keepImages($data, $schema, $entry);
        }

        // Where an image belongs but none is chosen yet, a placeholder shows
        // it (core's rule, D10). New entries only: an existing entry keeps
        // its own images.
        if (Plugin::getInstance()->getSettings()->placeholderImages && !$editing) {
            $placeholders = Plugin::getInstance()->domain->placeholders($pattern?->filled ?? [], $readOnly);
            $data = $placeholders->fill($data, $model);

            // No note: each placeholder is a step in "Finish this page".
            $placed = $placeholders->filled();
        }

        // The draft's search title and description into the entry's SEO
        // fields, where Ghostwriter may write them (SEO layer §9.3, §9.4):
        // never over a person's text. Saved with the rest into the Craft
        // draft, so nothing is live until the editor saves.
        $search = $this->search($session, $entry, $schema, $model, $data);

        if ($search !== null) {
            $data = $search['data'];
        }

        return [
            'title' => $draft->title(),
            'data' => $data,
            'search' => $search['applied'] ?? null,
            'schema' => $schema,
            'model' => $model,
            'existing' => $existing,
            'notes' => $notes,
            'built' => $built,
            'housePlaces' => $housePlaces,
            'placed' => $placed,
            'editing' => $editing,
        ];
    }

    /**
     * The session's search title and description written into the SEO
     * fields (core's SearchFields, with CraftSeoWriter for SEOmatic's and
     * plain fields' shapes). The fields are read as the entry has them with
     * the draft in, so a description inherited from the excerpt reads the
     * draft's excerpt. Only the SEO fields written change in the data.
     * Null when the session has neither text.
     *
     * @param array<int, array<string, mixed>> $schema
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, applied: SearchApplied}|null
     */
    private function search(Session $session, Entry $entry, array $schema, Schema $model, array $data): ?array
    {
        $state = SeoState::of($session);

        if ($state->meta->title === '' && $state->meta->description === '') {
            return null;
        }

        $current = MetaContexts::entryData($entry, $schema);
        $values = array_replace($current->values, $data);
        $applied = (new SearchFields(new CraftSeoFields(), new CraftSeoWriter()))->apply(
            $values,
            $model,
            $current->withValues($values),
            $state,
            MetaContexts::isNew($entry),
            MetaContexts::provenance($session),
        );

        foreach ($applied->values as $handle => $value) {
            if (!array_key_exists($handle, $values) || $values[$handle] !== $value) {
                $data[$handle] = $value;
            }
        }

        return ['data' => $data, 'applied' => $applied];
    }

    /**
     * The entry's own image fields that already hold something other than a
     * placeholder, carried into the data where the draft leaves them empty.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $schema
     * @return array<string, mixed>
     */
    private function keepImages(array $data, array $schema, Entry $entry): array
    {
        foreach ($schema as $spec) {
            $handle = $spec['handle'];

            if ($spec['type'] !== Assets::class || !empty($data[$handle])) {
                continue;
            }

            $value = $entry->getFieldValue($handle);
            $assets = $value instanceof AssetQuery ? (clone $value)->status(null)->all() : [];

            if (array_filter($assets, fn(Asset $asset) => $asset->filename !== Placeholders::FILENAME) !== []) {
                $data[$handle] = array_map(fn(Asset $asset) => (int) $asset->id, $assets);
            }
        }

        return $data;
    }

    /**
     * Every block ID in entry data, at any depth, so blocks that are kept
     * are updated in place rather than made again.
     *
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function blockIds(array $data): array
    {
        $ids = [];

        array_walk_recursive($data, function($value, $key) use (&$ids): void {
            if ($key === 'id' && is_numeric($value)) {
                $ids[] = (int) $value;
            }
        });

        return $ids;
    }

    /**
     * The build's notes (or the house style's places) without the links
     * since chosen: "Still to choose by hand: Hero: Image; Hero: Button
     * link." loses "Hero: Button link" once a "button link" is chosen, and
     * "(link still to choose)" places go once no link is left to choose.
     *
     * @param array<int, mixed> $notes
     * @param array<string, mixed> $chosen From MarkerResolver::chosenLinks().
     * @param array<string, mixed> $data
     * @return array<int, mixed>
     */
    public static function withoutChosen(array $notes, array $chosen, array $data, bool $places = false): array
    {
        $left = str_contains((string) json_encode($data), Markers::LINK_PREFIX);
        $keep = function(string $item) use ($chosen, $left): bool {
            $field = preg_match('/: ([^:]+)\z/u', $item, $named) === 1 ? Markers::normaliseHint($named[1]) : null;

            return !(($field !== null && isset($chosen[$field])) || (!$left && str_ends_with($item, '(link still to choose)')));
        };

        if ($places) {
            return array_values(array_filter($notes, fn($place) => !is_string($place) || $keep($place)));
        }

        $out = [];

        foreach ($notes as $note) {
            if (!is_string($note) || preg_match('/\A(Still to (?:choose|set) by hand[^:]*: )(.*)\.\z/su', $note, $match) !== 1) {
                $out[] = $note;

                continue;
            }

            $items = array_values(array_filter(explode('; ', $match[2]), $keep));

            if ($items !== []) {
                $out[] = $match[1] . implode('; ', $items) . '.';
            }
        }

        return $out;
    }
}
