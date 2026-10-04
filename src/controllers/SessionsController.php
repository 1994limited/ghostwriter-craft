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
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefStage;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\BriefThread;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\LinkTarget;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Review\Comments;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntrySimplifier;
use NineteenNinetyFour\Ghostwriter\Core\Text\HtmlToMarkdown;
use nineteenninetyfour\ghostwriter\drafts\Applier;
use nineteenninetyfour\ghostwriter\gaps\CraftLinkTargets;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\ApplyComments;
use nineteenninetyfour\ghostwriter\jobs\FillBrief;
use nineteenninetyfour\ghostwriter\jobs\RefreshLayouts;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The writing itself: the conversation, with the brief in it, and handing
 * the draft to the entry it is for.
 */
class SessionsController extends Controller
{
    use FindsPieces;

    /**
     * Start a piece of the chosen kind for the entry the panel is open on,
     * with the person's reply to the quick-details question ("What's it
     * called, and what should it say?"): the conversation opens with that
     * question and the reply, and Ghostwriter fills in the brief from it.
     * Nothing is kept until the person replies, so choosing a kind and
     * closing the panel leaves no empty piece behind.
     */
    public function actionOpen(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $type = $this->type((string) $this->request->getRequiredBodyParam('type'));
        $entry = $this->target($type);

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $reply = $this->reply();

        if ($reply === null) {
            return $this->refuse(Craft::t('ghostwriter', 'Write a line or two first.'));
        }

        $sessions = $plugin->domain->sessions();
        $viewer = $plugin->domain->viewer();
        $session = $sessions->open($this->newSession($type, $entry, $this->examples($type, $this->request->getBodyParam('examples')) ?? []), $viewer, Craft::t('ghostwriter', 'brief.ask'));
        $session = $sessions->details($session->id, $reply, $viewer);

        FillBrief::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * "Draft this" from the content plan: no question first. The piece
     * opens with the idea, and Ghostwriter fills in the brief from it.
     */
    public function actionFromIdea(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $id = (string) $this->request->getRequiredBodyParam('idea');
        $idea = null;

        foreach ($plugin->domain->ideas() as $candidate) {
            if ((string) $candidate->id === $id && $candidate->isOpen()) {
                $idea = $candidate;
            }
        }

        if ($idea === null) {
            throw new NotFoundHttpException('That idea is no longer on the plan.');
        }

        $type = $this->type((string) $this->request->getRequiredBodyParam('type'));
        $entry = $this->target($type);

        if ($idea->group !== $type->group) {
            throw new NotFoundHttpException('That idea is for another section.');
        }

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $session = $plugin->domain->sessions()->openFromIdea(
            $this->newSession($type, $entry, $this->examples($type, $this->request->getBodyParam('examples')) ?? []),
            $plugin->domain->viewer(),
            $idea->title,
            trim($idea->why . "\n\n" . $idea->notes),
        );

        // That idea is now in hand.
        try {
            $plugin->domain->plan()->start($idea->id, $session->id);
        } catch (NotFound) {
            // Gone from the plan meanwhile: the piece goes ahead.
        }

        FillBrief::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * "Try again" on the brief card: another brief from the same details,
     * keeping the answers the person changed in the card.
     */
    public function actionTryAgain(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();
        $type = $this->type($session->kind);

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        [$answers, $examples, $title] = $this->card($type);

        $session = $this->guarded(fn() => $plugin->domain->sessions()->tryAgain($session->id, $plugin->domain->viewer(), $answers, $examples, $title, Craft::t('ghostwriter', 'brief.try-again')));

        if ($session instanceof Response) {
            return $session;
        }

        FillBrief::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * "Looks right, start writing": the card, as the person left it, is the
     * brief. It is kept on the piece and the writing starts. Anything left
     * in [square brackets] is for the writer to ask about.
     */
    public function actionAgree(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();
        $type = $this->type($session->kind);

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        [$answers, $examples, $title] = $this->card($type);

        if ($refusal = $this->missing($type, $session, $answers)) {
            return $refusal;
        }

        $studio = $plugin->studio;
        $kind = $type->forSession($session);
        $session = $this->guarded(fn() => $plugin->domain->sessions()->agree($session->id, $plugin->domain->viewer(), fn(Brief $brief) => $studio->brief($kind, $brief), $answers, $examples, $title));

        if ($session instanceof Response) {
            return $session;
        }

        RunSessionTurn::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * The agreed brief changed ("Show the brief", then edit it). No turn
     * runs; Ghostwriter works from it from the next message.
     */
    public function actionEditBrief(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();
        $type = $this->type($session->kind);

        [$answers, $examples, $title] = $this->card($type);

        if ($refusal = $this->missing($type, $session, $answers)) {
            return $refusal;
        }

        $studio = $plugin->studio;
        $kind = $type->forSession($session);
        $session = $this->guarded(fn() => $plugin->domain->sessions()->editBrief($session->id, $plugin->domain->viewer(), fn(Brief $brief) => $studio->brief($kind, $brief), $answers, $examples, $title));

        return $session instanceof Response ? $session : $this->asJson((new Presenter())->detail($session));
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

    /**
     * The answers to the writer's questions (core's Studio\Asks), by
     * question id, sent as one message: an empty or missing one is
     * skipped. `more` is anything else the person added.
     */
    public function actionAnswers(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $answers = [];

        foreach ((array) $this->request->getBodyParam('answers', []) as $id => $answer) {
            $answers[(string) $id] = is_string($answer) ? mb_substr($answer, 0, 20000) : null;
        }

        $more = mb_substr(trim((string) $this->request->getBodyParam('more', '')), 0, 50000);

        $session = $this->guarded(fn() => $plugin->domain->sessions()->answerQuestions($session->id, $answers, $more, $plugin->domain->viewer()));

        if ($session instanceof Response) {
            return $session;
        }

        FillBrief::next($session);

        return $this->asJson((new Presenter())->detail($session));
    }

    public function actionMessage(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        $message = $this->reply();

        if ($message === null) {
            return $this->refuse('Write a message first.');
        }

        // Before the brief is agreed, the only message there is to send is
        // the reply to the quick-details question; the brief card is
        // answered with its own buttons.
        $stage = BriefThread::stage($session);

        if (!$stage->agreed() && $stage !== BriefStage::Details) {
            return $this->refuse(Craft::t('ghostwriter', 'Check the brief first, then start writing.'), 409);
        }

        // One run at a time: checked and started under the session's lock,
        // so two people sending at once can't both start one.
        $session = $this->guarded(fn() => $stage === BriefStage::Details
            ? $plugin->domain->sessions()->details($session->id, $message, $plugin->domain->viewer())
            : $plugin->domain->sessions()->send($session->id, $message, $plugin->domain->viewer()));

        if ($session instanceof Response) {
            return $session;
        }

        FillBrief::next($session);

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

        // Comments that weren't answered are applied again; a brief that
        // could not be filled in is filled in again; anything else is the
        // writer's turn.
        if (Comments::unanswered($session) !== null) {
            ApplyComments::start(['sessionId' => $session->id]);
        } else {
            FillBrief::next($session);
        }

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
            $value = self::refTags((new HtmlToMarkdown())->convert($value));
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
        }, inPlace: true);
    }

    /**
     * A gap resolved from its chip, in the draft itself, before "Use this
     * draft": the Preview and the Text tab show markers as chips, and a
     * chip's popover answers an ask, confirms, changes or removes a count,
     * or chooses a link. The answer goes in exactly as typed (core's
     * Gaps\MarkerResolver); no model. Saved like any hand edit, under the
     * session's lock (E7), and the layouts follow without a call. A gap
     * resolved here never reaches the entry, so Finish this page never
     * lists it.
     *
     * A chip in the Text tab says where it is: `path` (the draft) or `item`
     * and `part` (an extra). One in the Preview is found by its kind, hint
     * and order. A link in a field the draft doesn't hold (a button's
     * link, which the house style fills when the page is built) is kept in
     * the draft by its hint and put in wherever its sentinel turns up.
     */
    public function actionResolveGap(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
        $kind = (string) $this->request->getRequiredBodyParam('kind');
        $hint = (string) $this->request->getBodyParam('hint', '');
        $list = $this->request->getBodyParam('list');
        $occurrence = max(0, (int) $this->request->getBodyParam('occurrence', 0));
        $value = str_replace("\r", '', (string) $this->request->getBodyParam('value', ''));
        $reference = (string) $this->request->getBodyParam('reference', '');
        $path = json_decode((string) $this->request->getBodyParam('path', ''), true);
        $item = (string) $this->request->getBodyParam('item', '');
        $part = (string) $this->request->getBodyParam('part', '');

        if (!in_array($kind, MarkerResolver::KINDS, true) || mb_strlen($value) > 5000 || mb_strlen($hint) > 400) {
            return $this->refuse(Craft::t('ghostwriter', 'That gap isn’t in the draft any more.'));
        }

        $domain = Plugin::getInstance()->domain;
        $layouts = new DraftLayouts();
        $refusal = null;
        $gone = Craft::t('ghostwriter', 'That gap isn’t in the draft any more.');

        try {
            $session = $domain->sessions()->edit($session->id, $domain->viewer(), function(Session $session) use ($kind, $hint, $list, $occurrence, $value, $reference, $path, $item, $part, $layouts, $gone, &$refusal): ?bool {
                if ($session->draft === null) {
                    $refusal = $this->refuse(Craft::t('ghostwriter', 'There is no draft yet.'));

                    return false;
                }

                $data = Draft::parse($session->draft)->data;
                $extras = $layouts->core()->extras($session);

                if ($item !== '') {
                    $texts = self::extraText($extras, $item, $part);
                } elseif (is_array($path) && $path !== []) {
                    $texts = self::draftText($data, $path);
                } else {
                    $texts = [...MarkerResolver::leaves($data), ...self::extraTexts($extras)];
                }

                $found = MarkerResolver::find($texts, $kind, $hint, is_string($list) ? $list : null, $occurrence);

                // A link field the draft doesn't hold: kept by its hint.
                if ($found === null && $kind === 'link' && $item === '' && !is_array($path) && $value !== '') {
                    $before = $session->draft;
                    $session->draft = self::dump(MarkerResolver::chooseLink($data, $hint, $reference !== '' ? $reference : $value, $value));
                    $layouts->afterEdit($session, $before);

                    return null;
                }

                if ($found === null) {
                    $refusal = $this->refuse($gone);

                    return false;
                }

                if ($found['whole'] && $reference !== '') {
                    $value = $reference;
                }

                if ($found['path'][0] === self::EXTRA) {
                    try {
                        self::resolveExtra($session, $extras, $found, $value, $layouts);
                    } catch (InvalidArgumentException $exception) {
                        $refusal = $this->refuse(Craft::t('ghostwriter', $exception->getMessage()));

                        return false;
                    }

                    return null;
                }

                $node = &$data;

                foreach ($found['path'] as $step) {
                    $node = &$node[$step];
                }

                $node = MarkerResolver::apply($node, $found, $value);
                unset($node);

                // The same piece of writing in the same place: its unit keeps its id.
                $session->draft = self::dump($data);
                $layouts->afterEdit($session, $session->draft);

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
     * Entries a link to choose could point at: the hint's best matches, or
     * what the person typed into "Choose an entry". No model.
     */
    public function actionLinks(): Response
    {
        $session = $this->session();
        $q = trim((string) $this->request->getRequiredParam('q'));

        if ($q === '' || mb_strlen($q) > 200) {
            return $this->asJson(['entries' => []]);
        }

        $targets = (new CraftLinkTargets($session->siteId ? (int) $session->siteId : null))->search($q, 6);

        return $this->asJson(['entries' => array_map(fn(LinkTarget $target) => [
            'title' => $target->title,
            'url' => $target->url,
            'value' => $target->value,
        ], $targets)]);
    }

    /**
     * A layout chosen from the cards, for everyone on the piece (E7). No
     * model, and nothing is put into the entry until "Use this draft".
     */
    public function actionChooseLayout(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();
        $plan = (string) $this->request->getRequiredBodyParam('plan');
        $layouts = new DraftLayouts();
        $refusal = null;

        $session = $plugin->domain->sessions()->change($session->id, function(Session $session) use ($layouts, $plan, &$refusal) {
            try {
                $layouts->core()->choose($session, $plan);
            } catch (InvalidArgumentException $exception) {
                $refusal = $this->refuse(Craft::t('ghostwriter', 'That layout needs refreshing before it can be used.'), 409);

                return false;
            }

            $session->touch($this->me());

            return null;
        });

        if ($session === null) {
            throw new NotFoundHttpException('No such piece of writing.');
        }

        return $refusal ?? $this->asJson((new Presenter())->detail($session));
    }

    /**
     * "Refresh layouts": the planner asked again for the draft as it is
     * now. One model call, run in the queue like a turn, and one at a time.
     */
    public function actionRefreshLayouts(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $session = $this->session();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if ($session->draft === null) {
            return $this->refuse(Craft::t('ghostwriter', 'There is no draft yet.'));
        }

        $domain = $plugin->domain;
        $viewer = $domain->viewer();
        $claimed = false;

        $session = $domain->sessions()->change($session->id, function(Session $session) use ($domain, $viewer, &$claimed) {
            $claimed = $session->claim($viewer->id, $domain->options());

            return $claimed ? null : false;
        });

        if ($session === null) {
            throw new NotFoundHttpException('No such piece of writing.');
        }

        if (!$claimed) {
            return $this->busy(new Busy('Ghostwriter is still working on this piece.', $session->waitingOn($viewer)), Craft::t('ghostwriter', 'Ghostwriter is still working on this piece.'));
        }

        RefreshLayouts::start(['sessionId' => $session->id]);

        return $this->asJson((new Presenter())->detail($session));
    }

    /**
     * An extra changed in the Text tab (C1): the editor's words become its
     * source. Layouts that use it follow.
     */
    public function actionEditExtra(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
        $item = (string) $this->request->getRequiredBodyParam('item');
        $part = $this->request->getBodyParam('part');
        $value = (string) $this->request->getBodyParam('value');
        $value = $this->request->getBodyParam('format') === 'html' ? trim(self::refTags((new HtmlToMarkdown())->convert($value))) : trim(str_replace("\r", '', $value));

        if ($value === '' || mb_strlen($value) > 5000) {
            return $this->refuse(Craft::t('ghostwriter', 'An extra can’t be empty. Delete it instead.'));
        }

        return $this->extraEdit($session, function(Session $session, DraftLayouts $layouts) use ($item, $part, $value): void {
            $current = $layouts->core()->extras($session)->item($item) ?? throw new InvalidArgumentException('That extra is no longer there.');
            $named = is_string($part) && $part !== '' && $part !== 'text';

            // Unchanged (as stored, or as the page will say it): a count to
            // check keeps its marker (and stays to review).
            $stored = (string) ($named ? $current->part($part) : $current->text);

            if ($stored === $value || Markers::withoutChecks($stored) === $value) {
                return;
            }

            if ($named) {
                $layouts->core()->editExtra($session, $item, $current->text, array_merge($current->parts, [$part => $value]), $layouts->context($session));
            } else {
                $layouts->core()->editExtra($session, $item, $value, null, $layouts->context($session));
            }
        });
    }

    /**
     * An extra deleted in the Text tab: a layout that used it is arranged
     * again without it.
     */
    public function actionDeleteExtra(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
        $item = (string) $this->request->getRequiredBodyParam('item');

        return $this->extraEdit($session, function(Session $session, DraftLayouts $layouts) use ($item): void {
            $site = $layouts->context($session) ?? throw new InvalidArgumentException('This piece’s section is no longer there.');
            $extras = $layouts->core()->extras($session);

            // A whole extra ("x2"), or one item of it ("x2.1").
            $ids = $extras->item($item) !== null ? [$item] : [];

            foreach ($extras->all() as $extra) {
                if ($extra->id === $item) {
                    $ids = array_map(fn($one) => $one->id, $extra->items);
                }
            }

            if ($ids === []) {
                throw new InvalidArgumentException('That extra is no longer there.');
            }

            foreach ($ids as $id) {
                $layouts->core()->deleteExtra($session, $id, $site);
            }
        });
    }

    /**
     * "Remove link" on a link Ghostwriter added (the Text tab's popover):
     * its words stay, the link goes from the draft and its layouts, and
     * the writer won't put it back. No model. Under the session's lock like
     * a hand edit, so not while Ghostwriter is still working on the piece.
     */
    public function actionRemoveLink(): Response
    {
        $this->requirePostRequest();

        $session = $this->session();
        $href = (string) $this->request->getRequiredBodyParam('href');

        if (trim($href) === '' || mb_strlen($href) > 2000) {
            return $this->refuse(Craft::t('ghostwriter', 'That link isn’t in the draft any more.'));
        }

        return $this->extraEdit($session, fn(Session $session, DraftLayouts $layouts) => $layouts->removeLink($session, $href));
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
            $session->gaps = $result['gaps']->toArray();
        });

        // A provisional draft ("edited, not saved") opens with the entry
        // itself; only a draft in its own right needs naming in the address.
        // "Finish this page" opens by itself once the form has reloaded.
        return $this->asJson([
            'notes' => $result['notes'],
            'draftId' => $result['draft']->isProvisionalDraft ? null : $result['draft']->draftId,
            'finish' => $plugin->getSettings()->finishOpenAfterDraft,
        ]);
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
     * What the person typed, or null when there is nothing to send.
     */
    private function reply(): ?string
    {
        $message = trim((string) $this->request->getBodyParam('message'));

        return $message === '' || mb_strlen($message) > 50000 ? null : $message;
    }

    /**
     * A new piece of this kind for the entry the panel is open on.
     *
     * @param array<int, int> $examples
     */
    private function newSession(ContentType $type, Entry $entry, array $examples): Session
    {
        $session = Session::start(Format::Craft, $type->handle, [], $this->me(), $examples);
        $session->recordId = (int) $entry->getCanonicalId();
        $session->siteId = (int) $entry->siteId;
        $session->variant = count($entry->getSection()?->getEntryTypes() ?? []) > 1 ? $entry->getType()->handle : null;

        return $session;
    }

    /**
     * Entries to model a piece on, from the request: only ones from its own
     * section, at most six. Null when none were sent, to keep the card's.
     *
     * @return array<int, int>|null
     */
    private function examples(ContentType $type, mixed $examples): ?array
    {
        if (!is_array($examples)) {
            return null;
        }

        $ids = array_slice(array_values(array_filter($examples, 'is_numeric')), 0, Brief::MAX_EXAMPLES);

        return $ids ? array_map('intval', Entry::find()->id($ids)->section($type->group)->status(null)->fixedOrder()->ids()) : [];
    }

    /**
     * The brief card as the person left it: answers by question, the
     * entries ticked under "Model it on", and the working title.
     *
     * @return array{0: array<string, string>, 1: array<int, int>|null, 2: string|null}
     */
    private function card(ContentType $type): array
    {
        $answers = array_map('strval', array_filter((array) $this->request->getBodyParam('answers'), 'is_scalar'));
        $title = $this->request->getBodyParam('title');

        return [$answers, $this->examples($type, $this->request->getBodyParam('examples')), is_scalar($title) ? mb_substr(trim((string) $title), 0, 200) : null];
    }

    /**
     * Required answers left empty in the card, and answers too long, as the
     * brief screen checked them. Something in [square brackets] counts as
     * an answer: the writer asks about it.
     *
     * @param array<string, string> $answers
     */
    private function missing(ContentType $type, Session $session, array $answers): ?Response
    {
        $card = BriefThread::card($session);
        $errors = $type->missing($card ? $card->with($answers)->answers : $answers);

        if (!$errors) {
            return null;
        }

        $errors = array_map(fn(string $error) => Craft::t('ghostwriter', $error), $errors);
        $this->response->setStatusCode(422);

        return $this->asJson(['message' => reset($errors), 'errors' => $errors]);
    }

    /**
     * A change made under the session's lock, with core's refusals answered:
     * someone else's run (whose), a step out of turn, a piece not there.
     *
     * @param callable(): Session $change
     */
    private function guarded(callable $change): Session|Response
    {
        try {
            return $change();
        } catch (Busy $busy) {
            return $this->busy($busy);
        } catch (Conflict $conflict) {
            return $this->refuse(Craft::t('ghostwriter', $conflict->getMessage()), $conflict->status());
        } catch (NotFound|NotAllowed) {
            throw new NotFoundHttpException('No such piece of writing.');
        }
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
     * @param bool $inPlace Whether only values changed, where they are (click-to-edit).
     */
    private function handEdit(Session $session, callable $edit, bool $inPlace = false): Response
    {
        $domain = Plugin::getInstance()->domain;
        $refusal = null;

        try {
            $layouts = new DraftLayouts();
            $session = $domain->sessions()->edit($session->id, $domain->viewer(), function(Session $session) use ($edit, &$refusal, $layouts, $inPlace): ?bool {
                $before = $session->draft;

                try {
                    $session->draft = $edit($session);
                } catch (InvalidArgumentException $exception) {
                    $refusal = $this->refuse($exception->getMessage());

                    return false;
                }

                // The layouts follow the words: unit ids carried over, and a
                // layout that no longer fits marked stale. No model. A value
                // changed where it is keeps its id however much it changed:
                // it is the same piece of writing in the same place.
                $layouts->afterEdit($session, $inPlace ? $session->draft : $before);

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
     * A change to the extras, under the session's lock like a hand edit.
     *
     * @param callable(Session, DraftLayouts): void $change
     */
    private function extraEdit(Session $session, callable $change): Response
    {
        $domain = Plugin::getInstance()->domain;
        $layouts = new DraftLayouts();
        $refusal = null;

        try {
            $session = $domain->sessions()->edit($session->id, $domain->viewer(), function(Session $session) use ($change, $layouts, &$refusal): ?bool {
                try {
                    $change($session, $layouts);
                } catch (InvalidArgumentException $exception) {
                    $refusal = $this->refuse(Craft::t('ghostwriter', $exception->getMessage()));

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
     * A link to another page as Craft keeps it, `{entry:12@1:url||/contact}`,
     * back from the panel's HTML, where it was shown percent-encoded
     * (`%7Bentry:12@1:url%7C%7C/contact%7D`): written back encoded, Craft
     * wouldn't know it for a reference tag when the draft is used.
     */
    public static function refTags(string $markdown): string
    {
        return (string) preg_replace_callback(
            '/\]\(\s*<?(%7B(?:entry|asset|category)(?:%3A|:)[^()\s>]*?%7D)>?\s*\)/i',
            fn(array $m) => '](' . rawurldecode($m[1]) . ')',
            $markdown,
        );
    }

    /** Where an extra item's words are, in a found marker's path: `['@extra', itemId, part]`. */
    private const EXTRA = '@extra';

    /**
     * @param array<string, mixed> $data
     */
    private static function dump(array $data): string
    {
        return trim(\Symfony\Component\Yaml\Yaml::dump($data, 20, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
    }

    /**
     * The one text a chip in the Text tab is in.
     *
     * @param array<string, mixed> $data
     * @param array<int, mixed> $path
     * @return list<array{path: list<int|string>, text: string}>
     */
    private static function draftText(array $data, array $path): array
    {
        $node = $data;

        foreach ($path as $step) {
            if (!is_array($node) || !(is_int($step) || is_string($step)) || !array_key_exists($step, $node)) {
                return [];
            }

            $node = $node[$step];
        }

        return is_string($node) ? [['path' => array_values($path), 'text' => $node]] : [];
    }

    /**
     * @return list<array{path: list<int|string>, text: string}>
     */
    private static function extraText(Extras $extras, string $item, string $part): array
    {
        $found = $extras->item($item);
        $part = $part === '' ? 'text' : $part;
        $text = $found === null ? null : ($part === 'text' ? $found->text : ($found->parts[$part] ?? null));

        return is_string($text) ? [['path' => [self::EXTRA, $found->id, $part], 'text' => $text]] : [];
    }

    /**
     * Each extra item's words: its text, and the parts that aren't in it.
     *
     * @return list<array{path: list<int|string>, text: string}>
     */
    private static function extraTexts(Extras $extras): array
    {
        $texts = [];

        foreach ($extras->items() as $item) {
            $texts[] = ['path' => [self::EXTRA, $item->id, 'text'], 'text' => $item->text];

            foreach ($item->parts as $part => $words) {
                if (is_string($words) && $words !== '' && !str_contains($item->text, $words)) {
                    $texts[] = ['path' => [self::EXTRA, $item->id, (string) $part], 'text' => $words];
                }
            }
        }

        return $texts;
    }

    /**
     * The marker resolved in the extra, and the rest of the item kept in
     * step: a stat's count is in its text and its number. Confirmed, every
     * count from the same list becomes its value; changed or removed, a
     * stat's text is its new number and its label.
     *
     * @param array{path: list<int|string>, text: string, kind: string, match: string, occurrence: int, whole: bool} $found
     */
    private static function resolveExtra(Session $session, Extras $extras, array $found, string $value, DraftLayouts $layouts): void
    {
        $item = $extras->item((string) $found['path'][1]) ?? throw new InvalidArgumentException('That extra is no longer there.');
        $field = (string) $found['path'][2];
        $words = ['text' => $item->text, ...array_map('strval', $item->parts)];
        $words[$field] = MarkerResolver::apply($words[$field], $found, $value);

        if ($found['kind'] === 'check') {
            $marker = Markers::checks($found['match'])[0] ?? null;
            $confirmed = $marker !== null && Markers::normaliseHint($marker['value']) === Markers::normaliseHint($value);

            if (!$confirmed && isset($item->parts['value'], $item->parts['label']) && $field === 'value') {
                $words['text'] = trim($words['value'] . ' ' . Markers::withoutChecks($words['label']));
            }

            foreach ($words as $name => $text) {
                $words[$name] = (string) preg_replace_callback(Markers::CHECK_PATTERN, fn(array $m) => $marker !== null && Markers::normaliseHint(trim($m[2])) === Markers::normaliseHint($marker['list']) ? trim($m[1]) : $m[0], $text);
            }
        } else {
            foreach ($words as $name => $text) {
                if ($name !== $field && str_contains($text, $found['match'])) {
                    $words[$name] = MarkerResolver::apply($text, [...$found, 'occurrence' => 0], $value);
                }
            }
        }

        $text = trim($words['text']);
        unset($words['text']);

        $layouts->core()->editExtra($session, $item->id, $text, $words === [] ? null : $words, $layouts->context($session));
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
