<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use nineteenninetyfour\ghostwriter\drafts\Applier;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\sessions\Session;
use nineteenninetyfour\ghostwriter\types\ContentType;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The writing itself: the brief, the conversation, and handing the draft to
 * the entry it is for.
 */
class SessionsController extends Controller
{
    /**
     * A first attempt at the questionnaire from a title and a few notes. It
     * only fills in the form; nothing is started until the person says so.
     */
    public function actionBrief(): Response
    {
        $this->requirePostRequest();

        $type = $this->type((string) $this->request->getRequiredBodyParam('type'));

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $title = trim((string) $this->request->getBodyParam('title'));
        $notes = (string) $this->request->getBodyParam('notes');

        if ($title === '' || mb_strlen($title) > 200 || mb_strlen($notes) > 20000) {
            return $this->refuse('Give it a working title, and keep the notes under 20,000 characters.');
        }

        try {
            return $this->asJson(['answers' => Plugin::getInstance()->studio->draftBrief($type, $title, $notes)]);
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }
    }

    /**
     * Start writing from the questionnaire, for the entry the panel is open on.
     */
    public function actionStart(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $type = $this->type((string) $this->request->getRequiredBodyParam('type'));
        $entry = $this->target($type);

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $answers = array_map('strval', array_filter((array) $this->request->getBodyParam('answers'), 'is_scalar'));

        if ($errors = $type->missing($answers)) {
            $this->response->setStatusCode(422);

            return $this->asJson(['message' => reset($errors), 'errors' => $errors]);
        }

        // Entries to model this one piece on; only ones from its own section.
        $examples = array_slice(array_values(array_filter((array) $this->request->getBodyParam('examples'), 'is_numeric')), 0, 6);
        $examples = $examples ? array_map('intval', Entry::find()->id($examples)->section($type->section)->status(null)->fixedOrder()->ids()) : [];

        $session = Session::start($type->handle, $answers, $this->me(), $examples);
        $session->elementId = (int) $entry->getCanonicalId();
        $session->siteId = (int) $entry->siteId;
        $session->entryType = count($entry->getSection()?->getEntryTypes() ?? []) > 1 ? $entry->getType()->handle : null;
        $session->addMessage('user', $plugin->studio->brief($type, $session), $this->me());
        $session->run($this->me());

        $plugin->sessions->save($session);

        // Started from the content plan: that idea is now in hand.
        if ($idea = $this->request->getBodyParam('idea')) {
            $plugin->ideas->update((string) $idea, ['status' => \nineteenninetyfour\ghostwriter\planning\IdeaRepository::DRAFTED, 'session' => $session->id]);
        }

        RunSessionTurn::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * Open an entry that already exists for editing in conversation. The
     * entry as the form holds it becomes the session's draft, and changes
     * are asked for in the same way as on something new.
     */
    public function actionEdit(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $entry = $this->editable();
        $canonicalId = (int) $entry->getCanonicalId();
        $session = $this->sessionFor($entry);

        // A conversation already editing this entry, with changes not yet put
        // into it, carries on where it was; otherwise it starts again from
        // the entry as it stands.
        $fresh = (bool) $this->request->getBodyParam('fresh');

        if ($session->status === Session::WORKING || (!$fresh && $session->source === $canonicalId && $session->appliedAt === null && $this->wasEditing($session))) {
            return $this->asJson((new Presenter())->detail($session));
        }

        $entryType = $entry->getType();
        $schema = (new \nineteenninetyfour\ghostwriter\layouts\SchemaReader())->read($entryType);
        $data = (new EntrySimplifier())->simplify((new \nineteenninetyfour\ghostwriter\layouts\EntryData())->read($entry, $schema), $schema);

        // Always from the entry as it stands, which may have been edited by
        // hand since Ghostwriter last saw it.
        $session->source = $canonicalId;
        $session->elementId = $canonicalId;
        $session->siteId = (int) $entry->siteId;
        $session->entryType = count($entry->getSection()?->getEntryTypes() ?? []) > 1 ? $entryType->handle : null;
        $session->draft = trim(\Symfony\Component\Yaml\Yaml::dump(($entryType->hasTitleField ? ['title' => (string) $entry->title] : []) + $data, 20, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $session->status = Session::IDLE;
        $session->error = null;
        $session->appliedAt = null;
        $session->touch($this->me());

        if ($session->messages === [] || !$this->wasEditing($session)) {
            $session->addMessage('user', 'This entry already exists on the site. Its content as it stands is the current draft. I will ask for changes to it.');
            $session->addMessage('assistant', 'I have the entry as it stands. Tell me what to change.');
            $session->messages[array_key_last($session->messages)]['editing'] = true;
        }

        $plugin->sessions->save($session);

        return $this->asJson((new Presenter())->detail($session));
    }

    public function actionShow(): Response
    {
        return $this->asJson((new Presenter())->detail($this->session()));
    }

    public function actionMessage(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if ($session->status === Session::WORKING) {
            return $this->busy($session, 'Ghostwriter is still working on the last message.');
        }

        $message = trim((string) $this->request->getBodyParam('message'));

        if ($message === '' || mb_strlen($message) > 50000) {
            return $this->refuse('Write a message first.');
        }

        $session->addMessage('user', $message, $this->me());
        $session->run($this->me());

        $plugin->sessions->save($session);

        RunSessionTurn::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * Run a failed turn again, with the message that failed, so nothing has
     * to be typed twice.
     */
    public function actionRetry(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $last = $session->messages[array_key_last($session->messages) ?? 0] ?? null;

        if ($session->status !== Session::FAILED || ($last['role'] ?? null) !== 'user') {
            return $this->refuse('There is nothing to try again.', 409);
        }

        $session->status = Session::WORKING;
        $session->error = null;

        $plugin->sessions->save($session);

        RunSessionTurn::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * The draft edited by hand.
     */
    public function actionDraft(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
        $draft = (string) $this->request->getBodyParam('draft');

        if (trim($draft) === '' || mb_strlen($draft) > 120000) {
            return $this->refuse('The draft cannot be empty.');
        }

        $session->draft = $draft;
        $session->touch($this->me());

        Plugin::getInstance()->sessions->save($session);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * One piece of the draft edited where it is shown: a heading, a line, a
     * stretch of rich text. Rich text arrives as the HTML the person edited
     * and is kept as markdown, like the rest of the draft.
     */
    public function actionEditField(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();

        if ($session->status === Session::WORKING) {
            return $this->busy($session, 'Ghostwriter is still working on the draft. Try again when it has finished.');
        }

        try {
            $draft = Draft::parse((string) $session->draft);
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        $path = json_decode((string) $this->request->getBodyParam('path'), true);
        $value = (string) $this->request->getBodyParam('value');

        if (!is_array($path) || $path === [] || mb_strlen($value) > 60000) {
            return $this->refuse('That part of the draft could not be found.');
        }

        if ($this->request->getBodyParam('format') === 'html') {
            $value = (new HtmlToMarkdown())->convert($value);
        } else {
            $value = trim(str_replace("\r", '', $value));
        }

        $data = $draft->data;
        $node = &$data;

        foreach ($path as $step) {
            if (!is_array($node) || !array_key_exists($step, $node)) {
                return $this->refuse('That part of the draft could not be found.');
            }

            $node = &$node[$step];
        }

        // Only writing is edited here; a block or a list is changed in YAML.
        if (!is_scalar($node) && $node !== null) {
            return $this->refuse('Only text can be edited here.');
        }

        $node = $value;
        unset($node);

        $session->draft = trim(\Symfony\Component\Yaml\Yaml::dump($data, 20, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $session->touch($this->me());

        $plugin->sessions->save($session);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * Put the draft into the entry's Craft draft. The panel then reloads the
     * form to show it, and the person checks it and saves as usual.
     */
    public function actionApply(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();
        $type = $this->type($session->type)->forSession($session);
        $entry = $this->target($type);

        if ($session->draft === null) {
            return $this->refuse('There is no draft yet.');
        }

        try {
            Draft::parse($session->draft);
            $result = (new Applier())->apply($session, $type, $entry, Craft::$app->getUser()->getIdentity());
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        // Noted so the session can be shown as handed over, not still in progress.
        $session->appliedAt = Session::now();
        $session->elementId = (int) $result['draft']->getCanonicalId();
        $session->siteId = (int) $result['draft']->siteId;
        $session->touch($this->me());

        $plugin->sessions->save($session);

        // A provisional draft ("edited, not saved") opens with the entry
        // itself; only a draft in its own right needs naming in the address.
        return $this->asJson(['notes' => $result['notes'], 'draftId' => $result['draft']->isProvisionalDraft ? null : $result['draft']->draftId]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->sessions->delete($this->session());

        return $this->asJson(['deleted' => true]);
    }

    /**
     * The entry the panel is open on, as the form holds it: a draft or the
     * entry itself. It must be in the type's section, and the person must be
     * allowed to save it.
     */
    private function target(ContentType $type): Entry
    {
        $id = $this->request->getRequiredBodyParam('elementId');
        $siteId = $this->request->getBodyParam('siteId');

        $entry = Entry::find()
            ->id((int) $id)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->siteId($siteId ? (int) $siteId : null)
            ->status(null)
            ->one();

        if (!$entry || $entry->getSection()?->handle !== $type->section) {
            throw new NotFoundHttpException('That entry cannot be written into from here.');
        }

        if (!Craft::$app->getElements()->canSave($entry, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('You are not allowed to edit that entry.');
        }

        return $entry;
    }

    /**
     * The entry to edit, as the form holds it: the person's provisional
     * draft, a named draft, or the entry itself.
     */
    private function editable(): Entry
    {
        $entry = Entry::find()
            ->id((int) $this->request->getRequiredBodyParam('elementId'))
            ->drafts(null)
            ->provisionalDrafts(null)
            ->siteId(($siteId = $this->request->getBodyParam('siteId')) ? (int) $siteId : null)
            ->status(null)
            ->one();

        $section = $entry?->getSection();

        if (!$entry || !$section || !Plugin::getInstance()->types->enabled($section->handle) || $entry->getIsUnpublishedDraft()) {
            throw new NotFoundHttpException('That entry cannot be edited from here.');
        }

        if (!Craft::$app->getElements()->canSave($entry, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('You are not allowed to edit that entry.');
        }

        return $entry;
    }

    /**
     * The conversation this entry was written or last edited in, where there
     * was one, so its history is to hand; otherwise a new one, with the kind
     * the entry was modelled for if there is one, or the section's general brief.
     */
    private function sessionFor(Entry $entry): Session
    {
        $plugin = Plugin::getInstance();
        $id = (int) $entry->getCanonicalId();
        $section = $entry->getSection();

        foreach ($plugin->sessions->visibleTo((int) Craft::$app->getUser()->getId()) as $session) {
            if (($session->source === $id || $session->elementId === $id) && $plugin->types->find($session->type)) {
                return $session;
            }
        }

        $type = null;

        foreach ($plugin->types->forSection($section->handle) as $candidate) {
            if (in_array($id, $candidate->examples, true)) {
                $type = $candidate;

                break;
            }
        }

        return Session::start(($type ?? ContentType::generic($section))->handle, [], $this->me());
    }

    /**
     * Whether the conversation already turned to editing the saved entry.
     */
    private function wasEditing(Session $session): bool
    {
        foreach ($session->messages as $message) {
            if (!empty($message['editing'])) {
                return true;
            }
        }

        return false;
    }

    private function type(string $handle): ContentType
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->types->find($handle);

        if (!$type || !$plugin->types->enabled($type->section)) {
            throw new NotFoundHttpException('No such kind of content.');
        }

        return $type;
    }

    /**
     * The piece named in the request, if the signed-in person may see it:
     * anyone's when conversations are shared, otherwise only their own.
     * One they may not see is treated as not there at all.
     */
    private function session(): Session
    {
        $sessions = Plugin::getInstance()->sessions;
        $session = $sessions->find((string) $this->request->getParam('id'));

        if ($session === null || !$sessions->canSee($session, $this->me())) {
            throw new NotFoundHttpException('No such piece of writing.');
        }

        return $session;
    }

    private function me(): ?int
    {
        $id = Craft::$app->getUser()->getId();

        return $id === null ? null : (int) $id;
    }

    /**
     * One run at a time. When it is someone else's, say whose.
     */
    private function busy(Session $session, string $message): Response
    {
        if ($session->runBy !== null && $session->runBy !== $this->me()) {
            $message = Craft::t('ghostwriter', '{name} is waiting on Ghostwriter.', ['name' => Presenter::name($session->runBy)]);
        }

        return $this->refuse($message, 409);
    }
}
