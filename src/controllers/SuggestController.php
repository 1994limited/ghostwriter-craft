<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use DateTimeImmutable;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionState;
use nineteenninetyfour\ghostwriter\jobs\ReviewEdits;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\suggest\CraftAssetAlt;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use nineteenninetyfour\ghostwriter\suggest\SuggestEdits;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Suggest edits, behind an existing entry's edit screen. The entry is the
 * one the form is editing (its element ID: the person's provisional draft
 * once they have one), so the review reads exactly what they see.
 *
 * - guide: the latest review, re-checked against the entry as the form
 *   has it, for the guide (no model, no save). Polled while a review runs.
 * - start: a review, once someone has seen the confirm with its call
 *   count; queued, never in the request.
 * - decide: Accept (with the words that went in), Dismiss, It's still
 *   right, or Undo, for everyone who sees the review. The change itself
 *   is already in the person's form; nothing here saves the entry.
 * - another: "Write another (uses Ghostwriter)", one small call.
 * - alt / unalt: "Save to the image", the one save: alt text on the
 *   asset, after its own confirm, and Undo writing the old text back.
 */
class SuggestController extends Controller
{
    public function actionGuide(): Response
    {
        $entry = $this->entry();

        return $this->asJson($this->suggest()->forGuide($entry));
    }

    public function actionStart(): Response
    {
        $this->requirePostRequest();
        $entry = $this->entry();
        $plugin = Plugin::getInstance();

        if (!$plugin->studio->configured()) {
            return $this->refuse(Craft::t('ghostwriter', 'Add an API key first: Suggest edits reads the page with your AI provider.'));
        }

        try {
            $review = $this->suggest()->reviews()->start(EntryChecks::ref($entry), $plugin->domain->viewer(), new DateTimeImmutable());
        } catch (Busy $busy) {
            return $this->refuse($busy->messageFor(fn($id) => is_numeric($id) ? (string) (Craft::$app->getUsers()->getUserById((int) $id)?->getFriendlyName() ?? '') : ''), 409);
        }

        ReviewEdits::start([
            'reviewId' => $review->id,
            'elementId' => (int) $entry->id,
            'siteId' => (int) $entry->siteId,
            'replyLanguage' => Craft::$app->getUser()->getIdentity()?->getPreferredLanguage() ?? Craft::$app->language,
        ]);

        return $this->asJson($this->suggest()->forGuide($entry));
    }

    public function actionDecide(): Response
    {
        $this->requirePostRequest();
        $found = $this->review(decide: true);
        $decisions = $this->request->getRequiredBodyParam('decisions');

        if (!is_array($decisions) || $decisions === [] || count($decisions) > 100) {
            return $this->refuse(Craft::t('ghostwriter', 'Nothing to decide.'), 400);
        }

        $now = new DateTimeImmutable();
        $viewer = Plugin::getInstance()->domain->viewer();
        $reviews = $this->suggest()->reviews();
        $done = [];

        foreach ($decisions as $decision) {
            $id = is_array($decision) && is_string($decision['suggestion'] ?? null) ? mb_substr($decision['suggestion'], 0, 2000) : null;
            $state = is_array($decision) ? (string) ($decision['state'] ?? '') : '';

            if ($id === null || !in_array($state, ['accepted', 'dismissed', 'confirmed', 'open'], true)) {
                continue;
            }

            // Asset alt text is saved by "Save to the image" only.
            if ($state === 'accepted' && $found->find($id)?->anchor->scope === AnchorScope::Asset) {
                continue;
            }

            $answer = is_string($decision['answer'] ?? null) ? mb_substr($decision['answer'], 0, 200) : null;
            $text = is_string($decision['text'] ?? null) ? mb_substr($decision['text'], 0, 5000) : null;

            try {
                if ($state === 'open') {
                    $reviews->undo($found->id, $id, $viewer, $now);
                } else {
                    $reviews->decide($found->id, $id, SuggestionState::from($state), $viewer, $now, $answer, $text);
                }

                $done[] = $id;
            } catch (Conflict|NotFound|InvalidArgumentException) {
                // Already decided, or no longer in the review: nothing to change.
            }
        }

        return $this->asJson(['decided' => $done, 'version' => Plugin::getInstance()->editReviewStore->find($found->id)?->version]);
    }

    public function actionAnother(): Response
    {
        $this->requirePostRequest();
        $entry = $this->entry();
        $found = $this->review(decide: true);
        $now = new DateTimeImmutable();
        $suggestion = (string) $this->request->getRequiredBodyParam('suggestion');

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        try {
            $versions = $this->suggest()->reviews()->another($found->id, $suggestion, $this->suggest()->input($entry, $now), $now);
        } catch (Conflict|NotFound $conflict) {
            return $this->refuse($conflict->getMessage());
        } catch (ProviderException $failed) {
            Craft::warning("Ghostwriter: Write another failed: {$failed->getMessage()}", 'ghostwriter');

            return $this->refuse($failed->getMessage());
        } catch (InvalidArgumentException) {
            return $this->refuse(Craft::t('ghostwriter', 'Ghostwriter’s answer couldn’t be used. Try again, or write it yourself.'));
        }

        if ($versions === []) {
            return $this->refuse(SuggestEdits::text(new Message('suggest.review.another-none')));
        }

        return $this->asJson(['versions' => $versions]);
    }

    /**
     * "Save to the image": the alt text on the asset, now, and recorded as
     * done. The step's confirm has said it saves now, on every page using
     * the image.
     */
    public function actionAlt(): Response
    {
        $this->requirePostRequest();
        $found = $this->review(decide: true);
        $suggestion = $this->assetSuggestion($found, (string) $this->request->getRequiredBodyParam('suggestion'));
        $alt = trim((string) $this->request->getRequiredBodyParam('alt'));
        $assets = new CraftAssetAlt();
        $asset = $assets->asset($suggestion->anchor->asset);

        if ($asset === null) {
            return $this->refuse(Craft::t('ghostwriter', 'The image has gone.'), 404);
        }

        if ($alt === '' || mb_strlen($alt) > 300) {
            return $this->refuse(Craft::t('ghostwriter', 'Write the alt text first.'), 400);
        }

        // saveAssets:<volumeUid>, or savePeerAssets for someone else's upload.
        if (!Craft::$app->getElements()->canSave($asset, Craft::$app->getUser()->getIdentity())) {
            return $this->refuse(Craft::t('ghostwriter', 'Ask someone who can edit assets to add it.'), 403);
        }

        try {
            $before = $assets->save($asset, $alt);
        } catch (\RuntimeException $failed) {
            return $this->refuse($failed->getMessage());
        }

        $this->suggest()->reviews()->decide($found->id, $suggestion->id, SuggestionState::Accepted, Plugin::getInstance()->domain->viewer(), new DateTimeImmutable(), text: $alt);

        return $this->asJson(['before' => $before, 'message' => Craft::t('ghostwriter', 'Saved to {file}.', ['file' => $asset->filename])]);
    }

    /** Undo for "Save to the image": the alt text it had, written back. */
    public function actionUnalt(): Response
    {
        $this->requirePostRequest();
        $found = $this->review(decide: true);
        $suggestion = $this->assetSuggestion($found, (string) $this->request->getRequiredBodyParam('suggestion'));
        $assets = new CraftAssetAlt();
        $asset = $assets->asset($suggestion->anchor->asset);

        if ($asset === null) {
            return $this->refuse(Craft::t('ghostwriter', 'The image has gone.'), 404);
        }

        if (!Craft::$app->getElements()->canSave($asset, Craft::$app->getUser()->getIdentity())) {
            return $this->refuse(Craft::t('ghostwriter', 'Ask someone who can edit assets to add it.'), 403);
        }

        $assets->save($asset, mb_substr((string) $this->request->getBodyParam('before', ''), 0, 300));

        try {
            $this->suggest()->reviews()->undo($found->id, $suggestion->id, Plugin::getInstance()->domain->viewer(), new DateTimeImmutable());
        } catch (Conflict|NotFound) {
            // Nothing recorded to undo.
        }

        return $this->asJson(['message' => Craft::t('ghostwriter', 'The alt text on {file} is as it was.', ['file' => $asset->filename])]);
    }

    private function suggest(): SuggestEdits
    {
        return Plugin::getInstance()->suggest;
    }

    private function assetSuggestion(EditReview $review, string $id): Suggestion
    {
        $suggestion = $review->find($id);

        if (!$suggestion || $suggestion->anchor->scope !== AnchorScope::Asset || $suggestion->anchor->asset === null) {
            throw new NotFoundHttpException();
        }

        return $suggestion;
    }

    /**
     * A review the person may see (and, for a decision, act on), of an
     * entry they may save.
     */
    private function review(bool $decide = false): EditReview
    {
        $id = (string) $this->request->getRequiredBodyParam('reviewId');
        $review = Plugin::getInstance()->editReviewStore->find($id);

        if ($review === null) {
            throw new NotFoundHttpException();
        }

        $user = Craft::$app->getUser()->getIdentity();
        $entry = is_numeric($review->entry->id) ? Entry::find()->id((int) $review->entry->id)->siteId($review->entry->site)->status(null)->one() : null;
        $canEdit = $entry !== null && Craft::$app->getElements()->canSave($entry, $user);
        $viewer = Plugin::getInstance()->domain->viewer();
        $access = $this->suggest()->access();

        if (!$canEdit || !$access->canSee($review, $viewer, true)) {
            throw new ForbiddenHttpException();
        }

        if ($decide && !$access->canDecide($review, $viewer, true) && !$review->status->isRunning()) {
            throw new ForbiddenHttpException();
        }

        return $review;
    }

    /**
     * The entry as the form has it: by its own element ID, a provisional
     * draft's while one is being edited. Only an entry that exists already
     * (not a new one's draft), in a section Ghostwriter writes for, that
     * this person may save.
     */
    private function entry(): Entry
    {
        $id = (int) $this->request->getRequiredParam('elementId');
        $siteId = (int) ($this->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $entry = Entry::find()->id($id)->siteId($siteId)->drafts(null)->provisionalDrafts(null)->status(null)->one();

        if (!$entry instanceof Entry || $entry->getIsUnpublishedDraft() || $entry->getIsRevision()) {
            throw new NotFoundHttpException('Entry not found.');
        }

        $section = $entry->getSection();

        if ($section === null || !Plugin::getInstance()->types->enabled($section->handle)) {
            throw new NotFoundHttpException('Entry not found.');
        }

        if (!Craft::$app->getElements()->canSave($entry, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException();
        }

        return $entry;
    }
}
