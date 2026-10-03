<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use Craft;
use craft\base\Element;
use craft\elements\Entry;
use craft\elements\User;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
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
        private ?DraftValues $values = null,
        private FieldValues $fieldValues = new FieldValues(),
    ) {
        $this->values ??= new DraftValues();
    }

    /**
     * @return array{draft: Entry, notes: array<int, string>, gaps: SessionGaps}
     */
    public function apply(Session $session, ContentType $type, Entry $target, User $user): array
    {
        if ($session->draft === null) {
            throw new InvalidArgumentException('There is no draft yet.');
        }

        $entry = $this->draftFor($target, $user);
        $values = $this->values->for($session, $type, $entry);
        $notes = $values['notes'];

        if ($entry->getType()->hasTitleField) {
            $entry->title = $values['title'];
        }

        $entry->setFieldValues($this->fieldValues->forCraft($values['data'], $values['schema'], $values['existing']));

        // A new entry starts unpublished, so it saves at once and an AI
        // draft is never published by accident. The Enabled switch shows
        // off in the form, for the editor to turn on. Never an existing
        // entry: that is only ever a draft of it.
        if (!$values['editing'] && $entry->getIsUnpublishedDraft() && Plugin::getInstance()->getSettings()->draftsUnpublished) {
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

        // What the draft left for a person, so "Finish this page" can say
        // why ("Only you know this"). The content stays the truth.
        return ['draft' => $entry, 'notes' => $notes, 'gaps' => SessionGaps::fromDraft($values['built'], $values['housePlaces'], $values['placed'])];
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
