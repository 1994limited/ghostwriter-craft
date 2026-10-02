<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\helpers\UrlHelper;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\SuggestIdeas;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The content plan: ideas for what the site is missing, each one a click
 * away from a new entry with its brief filled in.
 */
class PlanController extends Controller
{
    public function actionShow(): Response
    {
        $plugin = Plugin::getInstance();
        $plan = $this->payload();

        // A failure is reported once; after that the screen starts clean.
        if ($this->state()->hasFailed()) {
            $plugin->domain->plan()->changeState(function(PlanState $state) use ($plugin): void {
                $state->recoverIfStale($plugin->domain->options());
                $state->forgetFailure();
            });
        }

        $this->view->registerAssetBundle(GhostwriterAsset::class);

        return $this->renderTemplate('ghostwriter/plan', [
            'configured' => $plugin->studio->configured(),
            'provider' => $plugin->studio->provider(),
            'keyName' => $plugin->providers::KEYS[$plugin->studio->provider()] ?? null,
            'config' => [
                'plan' => $plan,
                'configured' => $plugin->studio->configured(),
                'sections' => array_map(fn($section) => [
                    'handle' => $section->handle,
                    'title' => Craft::t('site', $section->name),
                    'types' => array_values(array_map(fn(ContentType $type) => ['handle' => $type->handle, 'title' => $type->title], $plugin->types->forSection($section->handle))),
                ], $plugin->types->sections()),
            ],
        ]);
    }

    public function actionStatus(): Response
    {
        return $this->asJson($this->payload());
    }

    public function actionSuggest(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();

        if ($refusal = $this->notConfigured()) {
            return $refusal;
        }

        if ($this->state()->isWorking()) {
            return $this->refuse('Ghostwriter is already looking for ideas.', 409);
        }

        $enabled = array_map(fn($section) => $section->handle, $plugin->types->sections());
        $asked = $this->request->getBodyParam('sections');
        $sections = array_values(array_intersect($enabled, is_array($asked) ? $asked : $enabled));
        $steer = mb_substr(trim((string) $this->request->getBodyParam('steer')), 0, 2000);

        if ($sections === []) {
            return $this->refuse('Choose at least one section to plan for.');
        }

        // Checked again under the lock, as someone else may have just asked.
        $plugin->domain->plan()->changeState(function(PlanState $state) use ($plugin): void {
            $state->recoverIfStale($plugin->domain->options());
            $state->begin('suggest', 'Ghostwriter is already looking for ideas.');
        });

        SuggestIdeas::start(['sections' => $sections, 'steer' => $steer]);

        return $this->asJson($this->payload());
    }

    /**
     * The person has looked over what was suggested: the ones they ticked
     * join the plan, the rest are kept as dismissed so they are not
     * suggested again. Dropping the lot keeps nothing.
     */
    public function actionAccept(): Response
    {
        $this->requirePostRequest();

        $plan = Plugin::getInstance()->domain->plan();

        if ($this->request->getBodyParam('discard')) {
            $plan->drop();
        } else {
            $plan->keep((array) $this->request->getBodyParam('chosen'));
        }

        return $this->asJson($this->payload());
    }

    public function actionAdd(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $title = trim((string) $this->request->getBodyParam('title'));
        $section = (string) $this->request->getBodyParam('section');

        if ($title === '' || mb_strlen($title) > 200 || !$plugin->types->enabled($section)) {
            return $this->refuse('An idea needs a title and a section Ghostwriter writes for.');
        }

        $plugin->domain->plan()->add([
            'title' => $title,
            'section' => $section,
            'type' => $this->request->getBodyParam('type') ?: null,
            'notes' => mb_substr((string) $this->request->getBodyParam('notes'), 0, 5000),
        ]);

        return $this->asJson($this->payload());
    }

    /**
     * An idea's words changed, or the idea dismissed or put back. A
     * dismissed idea is put back, and so is a started piece that isn't
     * finished (E5's "Back to ideas"); a finished piece is not (E8).
     */
    public function actionUpdate(): Response
    {
        $this->requirePostRequest();

        $plan = Plugin::getInstance()->domain->plan();
        $id = (string) $this->request->getRequiredBodyParam('id');
        $params = (array) $this->request->getBodyParams();
        $words = array_intersect_key($params, array_flip(['title', 'type', 'why', 'notes']));
        $status = $params['status'] ?? null;

        if ($status !== null && !in_array($status, [Idea::OPEN, Idea::DISMISSED], true)) {
            return $this->refuse('That is not a state an idea can be in.');
        }

        if (!Plugin::getInstance()->plans->find($id)) {
            throw new NotFoundHttpException('No such idea.');
        }

        match ($status) {
            Idea::OPEN => $plan->putBack($id, fn(Idea $idea) => Plugin::getInstance()->domain->finished($idea)),
            Idea::DISMISSED => $plan->dismiss($id),
            default => null,
        };

        if ($words !== []) {
            $plan->edit($id, $words);
        }

        return $this->asJson($this->payload());
    }

    /**
     * Empty the list: every idea in the given state goes. Started pieces are
     * never cleared this way; they go with their conversation.
     */
    public function actionClear(): Response
    {
        $this->requirePostRequest();

        $status = (string) $this->request->getBodyParam('status');

        if (!in_array($status, [Idea::OPEN, Idea::DISMISSED], true)) {
            return $this->refuse('Only open or dismissed ideas can be cleared.');
        }

        Plugin::getInstance()->domain->plan()->clear($status);

        return $this->asJson($this->payload());
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->domain->plan()->delete((string) $this->request->getRequiredBodyParam('id'));

        return $this->asJson($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $plugin = Plugin::getInstance();
        $state = $this->state();

        return [
            'status' => $state->status,
            'error' => $state->error,
            'pending' => array_map(fn(array $idea) => $idea + [
                'sectionTitle' => $this->sectionTitle((string) ($idea['section'] ?? '')),
                'typeTitle' => !empty($idea['type']) ? $plugin->types->find((string) $idea['type'])?->title : null,
            ], $state->pending),
            // Newest first, so ideas just kept from a suggestion are where
            // the person is looking; the screen groups them by section. A
            // piece whose conversation was removed is back to being just an
            // idea.
            'ideas' => array_values(array_map(fn(Idea $idea) => $this->present($idea), array_reverse($plugin->domain->ideas()))),
        ];
    }

    /**
     * The plan screen's state, with a search that stopped without finishing
     * shown as failed.
     */
    private function state(): PlanState
    {
        $domain = Plugin::getInstance()->domain;
        $state = $domain->plan()->state();
        $state->recoverIfStale($domain->options());

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Idea $found): array
    {
        $plugin = Plugin::getInstance();
        $domain = $plugin->domain;
        $session = $found->session !== null ? $plugin->sessions->find((string) $found->session) : null;
        $session?->recoverIfStale($domain->options());
        $progress = $session ? (new Presenter())->summary($session) : null;

        // With conversations kept private, someone else's piece can't be
        // opened, so it gets no link to resume it.
        if ($session && !$domain->access()->canSee($session, $domain->viewer())) {
            unset($progress['url']);
        }

        $idea = self::idea($found);

        return $idea + [
            // Where a started piece has got to, and where to pick it up.
            'stage' => $progress['stage'] ?? null,
            'finished' => $progress['finished'] ?? false,
            'resumeUrl' => $progress['url'] ?? null,
            'entryUrl' => $progress['entryUrl'] ?? null,
            // Who started it and who last changed it, when conversations are shared.
            'startedBy' => $progress['startedBy'] ?? null,
            'touchedBy' => $progress['touchedBy'] ?? null,
            'waitingOn' => $progress['waitingOn'] ?? null,
            'sectionTitle' => $this->sectionTitle($idea['section']),
            'typeTitle' => $idea['type'] ? $plugin->types->find($idea['type'])?->title : null,
            // A new entry with Ghostwriter open on it and this idea's brief filling itself in.
            'draftUrl' => $plugin->types->enabled($idea['section']) ? UrlHelper::cpUrl('ghostwriter/write/' . $idea['section'], ['idea' => $idea['id']]) : null,
        ];
    }

    /**
     * An idea as the screens show it.
     *
     * @return array{id: string, title: string, section: string, type: ?string, why: string, notes: string, status: string, source: string, session: ?string, createdAt: ?string}
     */
    public static function idea(Idea $idea): array
    {
        return [
            'id' => (string) $idea->id,
            'title' => $idea->title,
            'section' => $idea->group,
            'type' => $idea->kind,
            'why' => $idea->why,
            'notes' => $idea->notes,
            'status' => $idea->status,
            'source' => $idea->source,
            'session' => $idea->session === null ? null : (string) $idea->session,
            'createdAt' => $idea->createdAt,
        ];
    }

    private function sectionTitle(string $handle): string
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);

        return $section ? Craft::t('site', $section->name) : $handle;
    }
}
