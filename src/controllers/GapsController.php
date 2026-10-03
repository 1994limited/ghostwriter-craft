<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Studio\GapRequest;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\jobs\FillGap;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * "Finish this page" on an entry's edit screen: what is unfinished in the
 * entry as the form has it now (Craft's draft, provisional draft or the
 * entry itself, so nothing is posted and nothing is saved), whether the
 * guide was last left open, and the fixes that write.
 *
 * Checking never calls a model. Only "Write it for me" and "Write around
 * it" do, one small request each, in the queue.
 */
class GapsController extends Controller
{
    /** The user preference holding whether the guide was left open or minimised. */
    public const GUIDE_PREFERENCE = 'ghostwriterFinishGuide';

    public function actionCheck(): Response
    {
        $entry = $this->entry();

        return $this->asJson(Plugin::getInstance()->gaps->payload($entry, Craft::$app->getUser()->getIdentity()) + ['elementId' => (int) $entry->id]);
    }

    /**
     * Remember, for this person on every entry, whether the guide is open
     * or minimised.
     */
    public function actionGuide(): Response
    {
        $this->requirePostRequest();

        $state = $this->request->getRequiredBodyParam('state') === 'open' ? 'open' : 'minimised';
        $user = Craft::$app->getUser()->getIdentity();

        if ($user && $user->getPreference(self::GUIDE_PREFERENCE) !== $state) {
            Craft::$app->getUsers()->saveUserPreferences($user, [self::GUIDE_PREFERENCE => $state]);
        }

        return $this->asJson(['state' => $state]);
    }

    public static function rememberedGuide(): string
    {
        return Craft::$app->getUser()->getIdentity()?->getPreference(self::GUIDE_PREFERENCE) === 'open' ? 'open' : 'minimised';
    }

    /**
     * "Write it for me" (a line for an empty prose field, from the page's
     * own text) or "Write around it" (the sentence holding a fact to add,
     * without it). Queued; the guide polls actionFillStatus.
     */
    public function actionFill(): Response
    {
        $this->requirePostRequest();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $entry = $this->entry();
        $plugin = Plugin::getInstance();
        $gap = $plugin->gaps->report($entry)->find((string) $this->request->getRequiredBodyParam('gap'));

        if ($gap === null) {
            return $this->refuse(Craft::t('ghostwriter', 'That has been filled in already.'), 409);
        }

        $around = $gap->kind === GapKind::Ask;

        // Never a fact: core refuses anything else for a fact to add.
        if (!$around && !in_array($gap->kind, [GapKind::Required, GapKind::Expected], true)) {
            return $this->refuse(Craft::t('ghostwriter', 'Ghostwriter only writes around a fact it doesn’t know; it never supplies one.'), 422);
        }

        $sentence = trim((string) $this->request->getBodyParam('sentence', ''));
        $key = 'gap-fill:' . bin2hex(random_bytes(8));
        $plugin->store->putState($key, ['status' => 'working', 'by' => (int) Craft::$app->getUser()->getId()]);

        FillGap::start([
            'key' => $key,
            'by' => (int) Craft::$app->getUser()->getId(),
            'task' => $around ? GapRequest::WRITE_AROUND : GapRequest::SUMMARY,
            'label' => $gap->label,
            'text' => $around ? ($sentence !== '' ? $sentence : (string) $gap->excerpt) : $plugin->prose->fromEntry($entry),
            'missing' => $gap->hint,
            'limit' => $around ? null : $this->limit($gap->path->segments),
            'gap' => ['kind' => $gap->kind->value, 'path' => $gap->path->toString(), 'label' => $gap->label, 'hint' => $gap->hint, 'excerpt' => $gap->excerpt, 'occurrence' => $gap->occurrence],
        ]);

        return $this->asJson(['status' => 'working', 'id' => substr($key, 9)]);
    }

    public function actionFillStatus(string $id): Response
    {
        if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
            throw new NotFoundHttpException();
        }

        $store = Plugin::getInstance()->store;
        $state = $store->state("gap-fill:{$id}");

        // Only whoever asked sees the answer.
        if ($state === [] || (int) ($state['by'] ?? 0) !== (int) Craft::$app->getUser()->getId()) {
            throw new NotFoundHttpException();
        }

        if (($state['status'] ?? '') !== 'working') {
            $store->deleteState("gap-fill:{$id}");
        }

        return $this->asJson(['status' => $state['status'] ?? 'failed', 'text' => $state['text'] ?? null, 'message' => $state['message'] ?? null]);
    }

    /**
     * The entry as the form has it: by its own element ID, which is a
     * draft's or provisional draft's ID while one is being edited.
     */
    private function entry(): Entry
    {
        $id = (int) $this->request->getRequiredParam('elementId');
        $siteId = (int) ($this->request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);

        $entry = Entry::find()->id($id)->siteId($siteId)->drafts(null)->provisionalDrafts(null)->status(null)->one();

        if (!$entry instanceof Entry) {
            throw new NotFoundHttpException('Entry not found.');
        }

        if (!Craft::$app->getElements()->canView($entry, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException();
        }

        return $entry;
    }

    /**
     * A plain text field's character limit, for a line written into it.
     *
     * @param array<int, mixed> $segments
     */
    private function limit(array $segments): ?int
    {
        $handle = end($segments);
        $field = is_string($handle) ? Craft::$app->getFields()->getFieldByHandle($handle) : null;

        return $field instanceof \craft\fields\PlainText && $field->charLimit ? (int) $field->charLimit : null;
    }
}
