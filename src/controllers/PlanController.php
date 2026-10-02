<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\helpers\UrlHelper;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\SuggestIdeas;
use nineteenninetyfour\ghostwriter\planning\IdeaRepository;
use nineteenninetyfour\ghostwriter\planning\PlanState;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\types\ContentType;
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

        $plugin->planState->forgetFailure();

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

        if ($plugin->planState->get()['status'] === PlanState::WORKING) {
            return $this->refuse('Ghostwriter is already looking for ideas.', 409);
        }

        $enabled = array_map(fn($section) => $section->handle, $plugin->types->sections());
        $asked = $this->request->getBodyParam('sections');
        $sections = array_values(array_intersect($enabled, is_array($asked) ? $asked : $enabled));
        $steer = mb_substr(trim((string) $this->request->getBodyParam('steer')), 0, 2000);

        if ($sections === []) {
            return $this->refuse('Choose at least one section to plan for.');
        }

        $plugin->planState->update(['status' => PlanState::WORKING, 'error' => null, 'task' => 'suggest']);

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

        $plugin = Plugin::getInstance();
        $pending = $plugin->planState->get()['pending'];
        $chosen = array_map('intval', (array) $this->request->getBodyParam('chosen'));

        if (!$this->request->getBodyParam('discard')) {
            foreach ($pending as $i => $idea) {
                $added = $plugin->ideas->add($idea, 'suggested');

                if (!in_array($i, $chosen, true)) {
                    $plugin->ideas->update($added['id'], ['status' => IdeaRepository::DISMISSED]);
                }
            }
        }

        $plugin->planState->update(['pending' => []]);

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

        $plugin->ideas->add([
            'title' => $title,
            'section' => $section,
            'type' => $this->request->getBodyParam('type') ?: null,
            'notes' => mb_substr((string) $this->request->getBodyParam('notes'), 0, 5000),
        ]);

        return $this->asJson($this->payload());
    }

    public function actionUpdate(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $id = (string) $this->request->getRequiredBodyParam('id');

        if (!$plugin->ideas->find($id)) {
            throw new NotFoundHttpException('No such idea.');
        }

        $changes = array_intersect_key((array) $this->request->getBodyParams(), array_flip(['title', 'type', 'why', 'notes', 'status']));

        if (isset($changes['status']) && !in_array($changes['status'], [IdeaRepository::OPEN, IdeaRepository::DISMISSED, IdeaRepository::DRAFTED], true)) {
            return $this->refuse('That is not a state an idea can be in.');
        }

        $plugin->ideas->update($id, $changes);

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

        if (!in_array($status, [IdeaRepository::OPEN, IdeaRepository::DISMISSED], true)) {
            return $this->refuse('Only open or dismissed ideas can be cleared.');
        }

        Plugin::getInstance()->ideas->clear($status);

        return $this->asJson($this->payload());
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        Plugin::getInstance()->ideas->delete((string) $this->request->getRequiredBodyParam('id'));

        return $this->asJson($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $plugin = Plugin::getInstance();
        $state = $plugin->planState->get();

        return [
            'status' => $state['status'],
            'error' => $state['error'],
            'pending' => array_map(fn(array $idea) => $idea + [
                'sectionTitle' => $this->sectionTitle($idea['section']),
                'typeTitle' => $idea['type'] ? $plugin->types->find($idea['type'])?->title : null,
            ], $state['pending']),
            // Newest first, so ideas just kept from a suggestion are where
            // the person is looking; the screen groups them by section.
            'ideas' => array_values(array_map(fn(array $idea) => $this->present($idea), array_reverse($plugin->ideas->all()))),
        ];
    }

    /**
     * @param array<string, mixed> $idea
     * @return array<string, mixed>
     */
    private function present(array $idea): array
    {
        $plugin = Plugin::getInstance();
        $session = $idea['session'] ? $plugin->sessions->find($idea['session']) : null;
        $progress = $session ? (new Presenter())->summary($session) : null;

        // A piece whose conversation was removed is back to being just an idea.
        if ($idea['status'] === IdeaRepository::DRAFTED && !$session) {
            $idea['status'] = IdeaRepository::OPEN;
        }

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

    private function sectionTitle(string $handle): string
    {
        $section = Craft::$app->getEntries()->getSectionByHandle($handle);

        return $section ? Craft::t('site', $section->name) : $handle;
    }
}
