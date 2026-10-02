<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use InvalidArgumentException;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Busy;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Conflict;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotAllowed;
use NineteenNinetyFour\Ghostwriter\Core\Domain\NotFound;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use nineteenninetyfour\ghostwriter\drafts\Applier;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\Plugin;
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
        $examples = $examples ? array_map('intval', Entry::find()->id($examples)->section($type->group)->status(null)->fixedOrder()->ids()) : [];

        $session = Session::start(Format::Craft, $type->handle, $answers, $this->me(), $examples);
        $session->recordId = (int) $entry->getCanonicalId();
        $session->siteId = (int) $entry->siteId;
        $session->variant = count($entry->getSection()?->getEntryTypes() ?? []) > 1 ? $entry->getType()->handle : null;
        $session = $plugin->domain->sessions()->start($session, $plugin->studio->brief($type, $session), $plugin->domain->viewer());

        // Started from the content plan: that idea is now in hand.
        if ($idea = $this->request->getBodyParam('idea')) {
            try {
                $plugin->domain->plan()->start((string) $idea, $session->id);
            } catch (NotFound) {
                // Gone from the plan meanwhile: the piece goes ahead.
            }
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

        if ($session->isWorking() || (!$fresh && $session->source === $canonicalId && $session->appliedAt === null && $this->wasEditing($session))) {
            return $this->asJson((new Presenter())->detail($session));
        }

        $entryType = $entry->getType();
        $schema = (new \nineteenninetyfour\ghostwriter\layouts\SchemaReader())->read($entryType);
        $data = (new EntrySimplifier())->simplify((new \nineteenninetyfour\ghostwriter\layouts\EntryReader())->read($entry, $schema), $schema);

        // Always from the entry as it stands, which may have been edited by
        // hand since Ghostwriter last saw it.
        $session->source = $canonicalId;
        $session->editing = true;
        $session->recordId = $canonicalId;
        $session->siteId = (int) $entry->siteId;
        $session->variant = count($entry->getSection()?->getEntryTypes() ?? []) > 1 ? $entryType->handle : null;
        $session->draft = trim(\Symfony\Component\Yaml\Yaml::dump(($entryType->hasTitleField ? ['title' => (string) $entry->title] : []) + $data, 20, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        $session->status = Session::IDLE;
        $session->error = null;
        $session->appliedAt = null;
        $session->touch($this->me());

        if ($session->messages === [] || !$this->wasEditing($session)) {
            $session->addMessage('user', 'This entry already exists on the site. Its content as it stands is the current draft. I will ask for changes to it.');
            $session->addMessage('assistant', 'I have the entry as it stands. Tell me what to change.', extra: ['editing' => true]);
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

        $message = trim((string) $this->request->getBodyParam('message'));

        if ($message === '' || mb_strlen($message) > 50000) {
            return $this->refuse('Write a message first.');
        }

        // One run at a time: checked and started under the session's lock,
        // so two people sending at once can't both start one.
        try {
            $session = $plugin->domain->sessions()->send($session->id, $message, $plugin->domain->viewer());
        } catch (Busy $busy) {
            return $this->busy($busy);
        } catch (NotFound|NotAllowed) {
            throw new NotFoundHttpException('No such piece of writing.');
        }

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

        // Under the session's lock, like a new message, and recording who is
        // waiting on it.
        try {
            $session = $plugin->domain->sessions()->retry($session->id, $plugin->domain->viewer());
        } catch (Busy $busy) {
            return $this->busy($busy, 'Ghostwriter is already trying again.');
        } catch (NotFound|NotAllowed) {
            throw new NotFoundHttpException('No such piece of writing.');
        } catch (Conflict $conflict) {
            return $this->refuse($conflict->getMessage(), $conflict->status());
        }

        RunSessionTurn::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * The draft edited by hand. Saved under the session's lock, like a new
     * message, so it can't race a running turn or someone else's edit.
     */
    public function actionDraft(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
        $draft = (string) $this->request->getBodyParam('draft');

        if (trim($draft) === '' || mb_strlen($draft) > 120000) {
            return $this->refuse('The draft cannot be empty.');
        }

        return $this->handEdit($session, fn() => $draft);
    }

    /**
     * One piece of the draft edited where it is shown: a heading, a line, a
     * stretch of rich text. Rich text arrives as the HTML the person edited
     * and is kept as markdown, like the rest of the draft.
     */
    public function actionEditField(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
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

        // Read from the draft as it is under the lock, not as it was before.
        return $this->handEdit($session, function(Session $session) use ($path, $value): string {
            $data = Draft::parse((string) $session->draft)->data;
            $node = &$data;

            foreach ($path as $step) {
                if (!is_array($node) || !array_key_exists($step, $node)) {
                    throw new InvalidArgumentException('That part of the draft could not be found.');
                }

                $node = &$node[$step];
            }

            // Only writing is edited here; a block or a list is changed in YAML.
            if (!is_scalar($node) && $node !== null) {
                throw new InvalidArgumentException('Only text can be edited here.');
            }

            $node = $value;
            unset($node);

            return trim(\Symfony\Component\Yaml\Yaml::dump($data, 20, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        });
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
        $type = $this->type($session->kind)->forSession($session);
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

        // Noted so the session can be shown as handed over, not still in
        // progress; under the lock, so a turn finishing meanwhile is kept.
        $plugin->domain->sessions()->change($session->id, function(Session $session) use ($result): void {
            $session->markApplied($this->me());
            $session->recordId = (int) $result['draft']->getCanonicalId();
            $session->siteId = (int) $result['draft']->siteId;
        });

        // A provisional draft ("edited, not saved") opens with the entry
        // itself; only a draft in its own right needs naming in the address.
        return $this->asJson(['notes' => $result['notes'], 'draftId' => $result['draft']->isProvisionalDraft ? null : $result['draft']->draftId]);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $domain = Plugin::getInstance()->domain;
        $session = $this->session();

        // Shared, only whoever started it or someone who manages Ghostwriter.
        try {
            $domain->sessions()->delete($session->id, $domain->viewer(), Craft::t('ghostwriter', 'Only the person who started this piece, or an admin, can remove it.'));
        } catch (NotFound) {
            throw new NotFoundHttpException('No such piece of writing.');
        } catch (NotAllowed $refused) {
            throw new ForbiddenHttpException($refused->getMessage());
        }

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

        if (!$entry || $entry->getSection()?->handle !== $type->group) {
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

        foreach ($plugin->domain->sessions()->visible($plugin->domain->viewer()) as $session) {
            if (($session->source === $id || $session->recordId === $id) && $plugin->types->find($session->kind)) {
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

        return Session::start(Format::Craft, ($type ?? $plugin->types->generic($section))->handle, [], $this->me());
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

        if (!$type || !$plugin->types->enabled($type->group)) {
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
        $domain = Plugin::getInstance()->domain;

        try {
            return $domain->sessions()->find((string) $this->request->getParam('id'), $domain->viewer());
        } catch (NotFound|NotAllowed) {
            throw new NotFoundHttpException('No such piece of writing.');
        }
    }

    private function me(): ?int
    {
        $id = Craft::$app->getUser()->getId();

        return $id === null ? null : (int) $id;
    }

    /**
     * A change made to the draft by hand, under the session's lock: refused
     * while a turn is running, since the turn would write over it, and
     * worked out from the draft as it is once the lock is held, so two
     * people's edits can't undo each other.
     *
     * @param callable(Session): string $edit The new draft.
     */
    private function handEdit(Session $session, callable $edit): Response
    {
        $domain = Plugin::getInstance()->domain;
        $refusal = null;

        try {
            $session = $domain->sessions()->edit($session->id, $domain->viewer(), function(Session $session) use ($edit, &$refusal): ?bool {
                try {
                    $session->draft = $edit($session);
                } catch (InvalidArgumentException $exception) {
                    $refusal = $this->refuse($exception->getMessage());

                    return false;
                }

                return null;
            });
        } catch (Busy $busy) {
            return $this->busy($busy);
        } catch (NotFound|NotAllowed) {
            throw new NotFoundHttpException('No such piece of writing.');
        }

        return $refusal ?? $this->asJson((new Presenter())->detail($session));
    }

    /**
     * One run at a time. When it is someone else's, say whose.
     *
     * @param string|null $mine What to say when it is the person's own request.
     */
    private function busy(Busy $busy, ?string $mine = null): Response
    {
        $message = $busy->waitingOn !== null
            ? Craft::t('ghostwriter', '{name} is waiting on Ghostwriter.', ['name' => Presenter::name((int) $busy->waitingOn)])
            : ($mine ?? $busy->getMessage());

        return $this->refuse($message, $busy->status());
    }
}
