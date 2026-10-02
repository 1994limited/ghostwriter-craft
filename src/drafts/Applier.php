<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use nineteenninetyfour\ghostwriter\images\Placeholders;
use nineteenninetyfour\ghostwriter\layouts\EntryData;
use nineteenninetyfour\ghostwriter\layouts\HouseStyle;
use nineteenninetyfour\ghostwriter\layouts\PatternFinder;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\sessions\Session;
use nineteenninetyfour\ghostwriter\types\ContentType;

/**
 * Puts a session's draft into an entry as a Craft draft, never into the live
 * entry. On a new entry that is the unpublished draft Craft made when the
 * create screen opened; on an existing one, the person's own provisional
 * draft ("edited, not saved"), made if they do not have one yet.
 *
 * Nothing is published. The person reviews the filled-in form and saves it,
 * or discards the changes, as with any edit they made themselves.
 */
class Applier
{
    public function __construct(
        private SchemaReader $reader = new SchemaReader(),
        private PatternFinder $patterns = new PatternFinder(),
        private EntryBuilder $builder = new EntryBuilder(),
        private FieldValues $values = new FieldValues(),
    ) {
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

        $editing = $session->source !== null;
        $existing = [];

        if ($editing) {
            // Editing an entry: only the writing changes. Its images, links,
            // settings and blocks come from the entry as it stands in this
            // draft, not from what this kind of entry usually has.
            $pattern = [];
            $built = $this->builder->build($draft->data, $schema);
            $original = (new EntryData())->read($entry, $schema);
            $built['data'] = (new EntryMerger())->merge($built['data'], $original, $schema);
            $built['notes'] = [];
            $existing = $this->blockIds($original);
        } else {
            $pattern = $this->patterns->find($type->section, $schema, $entryType->handle, $type->where, $type->examples);
            $built = $this->builder->build($draft->data, $schema, $pattern, $type->defaults);
        }

        if ($entryType->hasTitleField) {
            $entry->title = $draft->title();
        }

        $data = $built['data'];
        $notes = $built['notes'];

        // What the model entries agree on place by place: settings and links
        // in the same position, nested items such as breadcrumbs, and the
        // markup around rich text. A new entry only; an existing one keeps
        // its own.
        if ($session->source === null && !empty($pattern['house'])) {
            $toFill = [];
            $data = (new HouseStyle())->apply($data, $schema, $pattern['house'], $toFill, ['id' => (int) $entry->getCanonicalId(), 'title' => (string) ($data['title'] ?? $draft->title())]);

            if ($toFill) {
                $notes[] = 'Still to set by hand, as it differs from page to page: ' . implode('; ', array_unique($toFill)) . '.';
            }
        }

        // Where an image belongs but none is chosen yet, a placeholder shows
        // it. New entries only: an existing entry keeps its own images.
        if (Plugin::getInstance()->getSettings()->placeholderImages && $session->source === null) {
            $placeholders = new Placeholders($pattern['filled'] ?? []);
            $data = $placeholders->fill($data, $schema);

            if ($placeholders->filled()) {
                $notes[] = 'A striped placeholder marks each image still to pick: ' . implode('; ', $placeholders->filled()) . '. Replace them before publishing.';
            }
        }

        $entry->setFieldValues($this->values->forCraft($data, $schema, $existing));

        // A draft is saved as Craft saves one while a person types: only the
        // essentials are checked, and the rest when they save the entry.
        $entry->setScenario(Element::SCENARIO_ESSENTIALS);

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new InvalidArgumentException('The draft could not be saved: ' . implode(' ', $entry->getFirstErrors()));
        }

        return ['draft' => $entry, 'notes' => $notes];
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
