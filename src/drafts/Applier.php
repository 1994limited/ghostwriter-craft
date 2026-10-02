<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Images\Placeholders;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\Layouts;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * Puts a session's draft into an entry as a Craft draft, never into the live
 * entry. On a new entry that is the unpublished draft Craft made when the
 * create screen opened; on an existing one, the person's own provisional
 * draft ("edited, not saved"), made if they do not have one yet.
 *
 * Nothing is published. The person reviews the filled-in form and saves it,
 * or discards the changes, as with any edit they made themselves. A new
 * entry starts with Enabled switched off (`draftsUnpublished`).
 */
class Applier
{
    public function __construct(
        private SchemaReader $reader = new SchemaReader(),
        private ?Layouts $layouts = null,
        private FieldValues $values = new FieldValues(),
    ) {
        $this->layouts ??= Plugin::getInstance()->layouts;
    }

    /**
     * @return array{draft: Entry, notes: array<int, string>}
     */
    public function apply(Session $session, ContentType $type, Entry $target, User $user): array
    {
        if ($session->draft === null) {
            throw new InvalidArgumentException('There is no draft yet.');
        }

        $draft = Draft::parse($session->draft);

        $entry = $this->draftFor($target, $user);
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
            $data = (new EntryMerger())->merge($this->layouts->build($draft->data, $model)->data, $original, $schema);
            $notes = [];
            $existing = $this->blockIds($original);
        } else {
            $pattern = $this->layouts->pattern($type->group, $model, $entryType->handle, $type->where, $type->examples);
            $built = $this->layouts->build($draft->data, $model, $pattern, $type->defaults);
            $data = $built->data;
            $notes = $built->notes;
        }

        if ($entryType->hasTitleField) {
            $entry->title = $draft->title();
        }

        // What the model entries agree on place by place: settings and links
        // in the same position, nested items such as breadcrumbs, and the
        // markup around rich text. A new entry only; an existing one keeps
        // its own.
        if ($pattern !== null) {
            $house = $this->layouts->houseStyle($data, $model, $pattern->house, (int) $entry->getCanonicalId(), (string) ($data['title'] ?? $draft->title()));
            $data = $house->data;

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
            $placeholders = Plugin::getInstance()->domain->placeholders($pattern?->filled ?? []);
            $data = $placeholders->fill($data, $model);

            if ($note = $placeholders->note()) {
                $notes[] = $note;
            }
        }

        $entry->setFieldValues($this->values->forCraft($data, $schema, $existing));

        // A new entry starts unpublished, so it saves at once and an AI
        // draft is never published by accident. The Enabled switch shows
        // off in the form, for the editor to turn on. Never an existing
        // entry: that is only ever a draft of it.
        if (!$editing && $entry->getIsUnpublishedDraft() && Plugin::getInstance()->getSettings()->draftsUnpublished) {
            $entry->enabled = false;
            $entry->setEnabledForSite(false);
            $notes[] = Craft::t('ghostwriter', 'Ghostwriter drafts start unpublished. Switch on Enabled when you’re ready.');
        }

        // A draft is saved as Craft saves one while a person types: only the
        // essentials are checked, and the rest when they save the entry.
        $entry->setScenario(Element::SCENARIO_ESSENTIALS);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new InvalidArgumentException('The draft could not be saved: ' . implode(' ', $entry->getFirstErrors()));
        }

        return ['draft' => $entry, 'notes' => $notes];
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
     * The draft to write into: the target itself when it is a draft, or the
     * person's provisional draft of it.
     */
    private function draftFor(Entry $target, User $user): Entry
    {
        if ($target->getIsDraft()) {
            return $target;
        }

        $provisional = Entry::find()
            ->draftOf($target)
            ->provisionalDrafts()
            ->draftCreator($user)
            ->siteId($target->siteId)
            ->status(null)
            ->one();

        /** @var Entry */
        return $provisional ?? Craft::$app->getDrafts()->createDraft($target, $user->id, null, null, [], true);
    }
}
