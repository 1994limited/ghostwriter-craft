<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\Entry;
use craft\fields\Assets;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Layout\BuiltEntry;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\Layouts;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;

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
     * }
     */
    public function for(Session $session, ContentType $type, Entry $entry, bool $readOnly = false): array
    {
        if ($session->draft === null) {
            throw new InvalidArgumentException('There is no draft yet.');
        }

        $draft = Draft::parse($session->draft);
        $entryType = $entry->getType();
        $schema = $this->reader->read($entryType);
        $model = Schema::fromSpecs($schema);

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

        return [
            'title' => $draft->title(),
            'data' => $data,
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
}
