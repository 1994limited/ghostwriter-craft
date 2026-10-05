<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\helpers\UrlHelper;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\PlanState;
use yii\base\Component;

/**
 * Getting started: the steps that take a site from installed to writing,
 * in the order they are worth doing, each with whether it is done. Every
 * step is read from the state of the site, so nothing has to be ticked off
 * by hand and a step undone (a guide deleted) shows as undone again.
 */
class Onboarding extends Component
{
    /**
     * @return array<int, array{key: string, title: string, text: string, done: bool, working: bool, optional: bool, detail: ?string, action: ?array<string, mixed>}>
     */
    public function steps(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $user = Craft::$app->getUser();
        $canSettle = $user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        $sections = $plugin->types->sections();
        $configured = $plugin->studio->configured();

        $types = array_sum(array_map(fn($section) => count($plugin->types->forSection($section->handle)), $sections));
        $suggested = array_sum(array_map(fn($section) => count($plugin->types->suggestions($section->handle)->suggestions), $sections));
        $kindsWorking = (bool) array_filter($sections, fn($section) => $plugin->types->suggestions($section->handle)->isWorking() || $plugin->types->analysis($section->handle)->isWorking());
        $ideas = count($plugin->plans->ideas());
        $plan = $this->planState();
        $pending = count($plan->pending);
        $voice = $plugin->domain->guide(Guide::VOICE);
        $voiceState = $plugin->domain->guideState(Guide::VOICE);
        $imagery = $plugin->domain->guide(Guide::IMAGERY);
        $imageryState = $plugin->domain->guideState(Guide::IMAGERY);

        $settingsLink = $canSettle ? ['type' => 'link', 'label' => Craft::t('ghostwriter', 'Open the settings'), 'url' => UrlHelper::cpUrl('settings/plugins/ghostwriter')] : null;
        $connectionsLink = Plugin::canManage(Craft::$app->getUser()->getIdentity()) ? ['type' => 'link', 'label' => Craft::t('ghostwriter', 'Set up in Connections'), 'url' => UrlHelper::cpUrl('ghostwriter/connections')] : null;

        return [
            [
                'key' => 'key',
                'title' => Craft::t('ghostwriter', 'Connect a model'),
                'text' => Craft::t('ghostwriter', 'Ghostwriter writes with Claude, ChatGPT or Gemini, on your own account. Set up its key in Connections: open the provider’s page, make a key, paste it in. It is kept encrypted and only ever sent to that provider. A key in your .env file still works, and wins.'),
                'done' => $configured,
                'working' => false,
                'optional' => false,
                'detail' => $configured
                    ? Craft::t('ghostwriter', 'Writing with {provider}.', ['provider' => $this->providerName($plugin->studio->provider())])
                    : Craft::t('ghostwriter', 'Set up {provider} in Connections (or set {key} in .env), then reload this page.', ['provider' => $this->providerName($plugin->studio->provider()), 'key' => $plugin->providers::KEYS[$plugin->studio->provider()] ?? 'the API key']),
                'action' => $connectionsLink ?? $settingsLink,
            ],
            [
                'key' => 'sections',
                'title' => Craft::t('ghostwriter', 'Choose where it writes'),
                'text' => Craft::t('ghostwriter', 'Ghostwriter writes for every section unless you choose some. A “Write with Ghostwriter” button appears on their new entries.'),
                'done' => $sections !== [],
                'working' => false,
                'optional' => false,
                'detail' => $settings->sections === []
                    ? Craft::t('ghostwriter', 'Writing for every section: {list}.', ['list' => implode(', ', array_map(fn($section) => Craft::t('site', $section->name), $sections))])
                    : Craft::t('ghostwriter', 'Writing for {list}.', ['list' => implode(', ', array_map(fn($section) => Craft::t('site', $section->name), $sections))]),
                'action' => $settingsLink,
            ],
            [
                'key' => 'voice',
                'title' => Craft::t('ghostwriter', 'Learn your voice'),
                'text' => Craft::t('ghostwriter', 'Ghostwriter reads what you have published and writes a guide to how you sound. Everything it writes follows the guide, which you can edit.'),
                'done' => $voice->exists(),
                'working' => $voiceState->isWorking(),
                'optional' => false,
                'detail' => $voiceState->hasFailed() ? $voiceState->error : null,
                'action' => $voice->exists()
                    ? ['type' => 'link', 'label' => Craft::t('ghostwriter', 'Review the guide'), 'url' => UrlHelper::cpUrl('ghostwriter/voice')]
                    : ['type' => 'post', 'label' => Craft::t('ghostwriter', 'Write the voice guide'), 'route' => 'voice/scan', 'data' => [], 'needsKey' => true],
            ],
            [
                'key' => 'kinds',
                'title' => Craft::t('ghostwriter', 'Teach it your kinds of content'),
                'text' => Craft::t('ghostwriter', 'Ghostwriter looks at each section and suggests the kinds of content in it, such as a press release or a case study. Learn the ones you write often and each gets a brief of its own.'),
                'done' => $types > 0,
                'working' => $kindsWorking,
                'optional' => false,
                'detail' => match (true) {
                    $types > 0 => Craft::t('ghostwriter', '{count, plural, =1{# kind} other{# kinds}} learned.', ['count' => $types]),
                    $suggested > 0 => Craft::t('ghostwriter', '{count, plural, =1{# suggestion is} other{# suggestions are}} waiting on the Overview.', ['count' => $suggested]),
                    default => null,
                },
                'action' => $suggested > 0 || $types > 0
                    ? ['type' => 'link', 'label' => Craft::t('ghostwriter', 'Review on the Overview'), 'url' => UrlHelper::cpUrl('ghostwriter')]
                    : ['type' => 'post', 'label' => Craft::t('ghostwriter', 'Suggest kinds'), 'route' => 'sections/suggest-kinds', 'data' => [], 'needsKey' => true],
            ],
            [
                'key' => 'imagery',
                'title' => Craft::t('ghostwriter', 'Describe your images'),
                'text' => Craft::t('ghostwriter', 'Ghostwriter looks at the pictures your entries use and writes down the house style, so the photographs it finds and the images it makes belong beside them.'),
                'done' => $imagery->exists(),
                'working' => $imageryState->isWorking(),
                'optional' => true,
                'detail' => $imageryState->hasFailed() ? $imageryState->error : null,
                'action' => $imagery->exists()
                    ? ['type' => 'link', 'label' => Craft::t('ghostwriter', 'Review the image style'), 'url' => UrlHelper::cpUrl('ghostwriter/imagery')]
                    : ['type' => 'post', 'label' => Craft::t('ghostwriter', 'Describe the images'), 'route' => 'imagery/scan', 'data' => ['sections' => array_map(fn($section) => $section->handle, $sections)], 'needsKey' => true],
            ],
            [
                'key' => 'plan',
                'title' => Craft::t('ghostwriter', 'Plan what to write'),
                'text' => Craft::t('ghostwriter', 'Ghostwriter reads the whole site and suggests entries it is missing. Keep the good ones on the content plan; each opens a new entry with its brief filled in.'),
                'done' => $ideas > 0,
                'working' => $plan->isWorking(),
                'optional' => true,
                'detail' => $pending > 0 ? Craft::t('ghostwriter', '{count, plural, =1{# suggestion is} other{# suggestions are}} waiting to be looked over.', ['count' => $pending]) : null,
                'action' => $ideas > 0 || $pending > 0
                    ? ['type' => 'link', 'label' => Craft::t('ghostwriter', 'Open the content plan'), 'url' => UrlHelper::cpUrl('ghostwriter/plan')]
                    : ['type' => 'post', 'label' => Craft::t('ghostwriter', 'Suggest ideas'), 'route' => 'plan/suggest', 'data' => [], 'needsKey' => true],
            ],
            [
                'key' => 'write',
                'title' => Craft::t('ghostwriter', 'Write something'),
                'text' => Craft::t('ghostwriter', 'Start a new entry with Ghostwriter beside it. Answer a short brief, talk the draft through, then put it into the entry and save it as usual.'),
                'done' => $plugin->sessions->all() !== [],
                'working' => false,
                'optional' => false,
                'detail' => null,
                'action' => $sections ? [
                    'type' => 'write',
                    'label' => Craft::t('ghostwriter', 'Start writing'),
                    'options' => array_map(fn($section) => ['label' => Craft::t('site', $section->name), 'url' => UrlHelper::cpUrl('ghostwriter/write/' . $section->handle)], $sections),
                ] : null,
            ],
        ];
    }

    /**
     * What each step of the wizard shows beyond its title and status: the
     * sections to choose from, the start of each guide, the kinds suggested
     * and learned, how the plan stands.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $user = Craft::$app->getUser();
        $enabled = array_map(fn($section) => $section->handle, $plugin->types->sections());
        $plan = $this->planState();

        return [
            'canChangeSettings' => $user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'canHide' => $this->canToggle(),
            'provider' => $this->providerName($plugin->studio->provider()),
            'keyName' => $plugin->providers::KEYS[$plugin->studio->provider()] ?? null,
            'otherKey' => $this->otherKey(),
            'sections' => array_map(fn($section) => [
                'handle' => $section->handle,
                'title' => Craft::t('site', $section->name),
                'entries' => (int) \craft\elements\Entry::find()->section($section->handle)->status('live')->count(),
                'writeFor' => in_array($section->handle, $enabled, true),
                'chosen' => in_array($section->handle, $settings->sections, true),
                'voice' => $settings->voiceSections === [] || in_array($section->handle, $settings->voiceSections, true),
            ], Craft::$app->getEntries()->getAllSections()),
            'voice' => $this->guide($plugin->domain->guide(Guide::VOICE)->body, $plugin->domain->guideState(Guide::VOICE)),
            'imagery' => $this->guide($plugin->domain->guide(Guide::IMAGERY)->body, $plugin->domain->guideState(Guide::IMAGERY)),
            // Kinds are looked for by themselves only here, as the step opens.
            'autoKinds' => $settings->suggestKindsAutomatically,
            'kinds' => array_map(fn($section) => [
                'handle' => $section->handle,
                'title' => Craft::t('site', $section->name),
                'state' => $plugin->types->suggestions($section->handle)->status,
                'error' => $plugin->types->suggestions($section->handle)->error,
                'suggestions' => array_map(fn(array $kind) => array_intersect_key($kind, array_flip(['id', 'title', 'description', 'why', 'exampleTitles'])), $plugin->types->presented($section->handle)),
                'learning' => $plugin->types->analysis($section->handle)->toArray(),
                'types' => array_values(array_map(fn($type) => ['title' => $type->title, 'url' => UrlHelper::cpUrl('ghostwriter/types/' . $type->handle)], $plugin->types->forSection($section->handle))),
            ], $plugin->types->sections()),
            'plan' => [
                'ideas' => count(array_filter($plugin->domain->ideas(), fn(Idea $idea) => $idea->isOpen())),
                'pending' => count($plan->pending),
                'status' => $plan->status,
                'error' => $plan->error,
                'url' => UrlHelper::cpUrl('ghostwriter/plan'),
            ],
        ];
    }

    /**
     * A guide as the wizard shows it: its opening, rendered, and how the job
     * writing it stands.
     *
     * @return array<string, mixed>
     */
    private function guide(string $markdown, GuideState $state): array
    {
        $opening = mb_substr(trim($markdown), 0, 900);

        return [
            'exists' => trim($markdown) !== '',
            'status' => $state->status,
            'error' => $state->error,
            'scanned' => count($state->scanned),
            'excerpt' => $opening === '' ? '' : (string) (new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($opening . (mb_strlen(trim($markdown)) > 900 ? ' …' : '')),
        ];
    }

    /**
     * How far setup has got, counting only the steps it needs: the optional
     * ones are shown apart, so the bar reaches the end once setup is done.
     *
     * @return array{done: int, total: int, complete: bool, hidden: bool}
     */
    public function progress(): array
    {
        $required = array_filter($this->steps(), fn(array $step) => !$step['optional']);
        $done = count(array_filter($required, fn(array $step) => $step['done']));

        return [
            'done' => $done,
            'total' => count($required),
            'complete' => $done === count($required),
            'hidden' => $this->hidden(),
        ];
    }

    /**
     * Whether this person may hide or show Get started. It is hidden for
     * the whole site, so it is for whoever manages Ghostwriter: an admin.
     * It is Ghostwriter's own state, not project config, so it can be
     * changed where admin changes are not allowed.
     */
    public function canToggle(): bool
    {
        return Plugin::canManage(Craft::$app->getUser()->getIdentity());
    }

    /**
     * The first step still to do, with its place in the list: a required
     * one first, since those are what setting up needs.
     *
     * @return array<string, mixed>|null
     */
    public function nextStep(): ?array
    {
        $steps = $this->steps();
        $open = array_filter($steps, fn(array $step) => !$step['done']);
        $required = array_filter($open, fn(array $step) => !$step['optional']);

        foreach ([$required, $open] as $candidates) {
            if ($candidates !== []) {
                $i = array_key_first($candidates);

                return $steps[$i] + ['number' => $i + 1];
            }
        }

        return null;
    }

    /**
     * The plan screen's state, with a search that stopped without finishing
     * shown as failed.
     */
    private function planState(): PlanState
    {
        $domain = Plugin::getInstance()->domain;
        $state = $domain->plan()->state();
        $state->recoverIfStale($domain->options());

        return $state;
    }

    public function hidden(): bool
    {
        return (bool) (Plugin::getInstance()->store->state('onboarding')['hidden'] ?? false);
    }

    public function hide(bool $hidden = true): void
    {
        Plugin::getInstance()->store->putState('onboarding', ['hidden' => $hidden]);
    }

    /**
     * Another writing provider whose key is already set, when the chosen
     * one has none: choosing it is quicker than getting a new key.
     */
    private function otherKey(): ?string
    {
        $plugin = Plugin::getInstance();
        $chosen = $plugin->studio->provider();

        if ($plugin->providers->configured()) {
            return null;
        }

        foreach (\nineteenninetyfour\ghostwriter\models\Settings::PROVIDERS as $provider) {
            if ($provider !== $chosen && $plugin->providers->key($provider) !== null) {
                return $this->providerName($provider);
            }
        }

        return null;
    }

    private function providerName(string $provider): string
    {
        return \nineteenninetyfour\ghostwriter\models\Settings::providerNames()[$provider] ?? $provider;
    }
}
