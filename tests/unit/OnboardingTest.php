<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\web\View;
use nineteenninetyfour\ghostwriter\sessions\Session;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use nineteenninetyfour\ghostwriter\types\ContentType;

/**
 * Getting started: each step read from the state of the site.
 */
class OnboardingTest extends TestCase
{
    use Sites;

    protected function _before(): void
    {
        parent::_before();

        $this->makePressSection();
        $this->signIn(admin: true);
    }

    public function testEachStepFollowsTheStateOfTheSite(): void
    {
        $this->unfake();
        $this->plugin->providers->keys['anthropic'] = null;

        $steps = $this->steps();

        $this->assertSame(['key', 'sections', 'voice', 'kinds', 'imagery', 'plan', 'write'], array_keys($steps));
        $this->assertFalse($steps['key']['done']);
        $this->assertSame('Add ANTHROPIC_API_KEY to .env, then reload this page.', $steps['key']['detail']);
        $this->assertTrue($steps['sections']['done']);
        $this->assertSame('Writing for every section: Press.', $steps['sections']['detail']);
        $this->assertSame(['type' => 'post', 'label' => 'Write the voice guide', 'route' => 'voice/scan', 'data' => [], 'needsKey' => true], $steps['voice']['action']);
        $this->assertTrue($steps['imagery']['optional']);
        $this->assertSame(['done' => 1, 'total' => 7, 'complete' => false, 'hidden' => false], $this->plugin->onboarding->progress());

        // Doing each thing ticks it off.
        $this->plugin->providers->keys['anthropic'] = 'test-key';
        $this->plugin->voiceGuide->save('# Tone of voice');
        $this->plugin->kinds->store('press', [['title' => 'Coverage', 'description' => '', 'why' => '', 'examples' => [1, 2], 'entryType' => null]], 2);

        $steps = $this->steps();
        $this->assertTrue($steps['key']['done']);
        $this->assertSame('Writing with Claude (Anthropic).', $steps['key']['detail']);
        $this->assertTrue($steps['voice']['done']);
        $this->assertSame('ghostwriter/voice', substr($steps['voice']['action']['url'], -17));

        // Suggestions waiting are not the same as kinds learned.
        $this->assertFalse($steps['kinds']['done']);
        $this->assertSame('1 suggestion is waiting on the dashboard.', $steps['kinds']['detail']);

        $this->plugin->types->save(ContentType::fromArray('coverage', ['title' => 'Coverage', 'section' => 'press', 'questions' => [['handle' => 'q', 'label' => 'Q']]]));
        $this->plugin->sessions->save(Session::start(ContentType::GENERIC . 'press', [], \Craft::$app->getUser()->getId()));

        $steps = $this->steps();
        $this->assertTrue($steps['kinds']['done']);
        $this->assertTrue($steps['write']['done']);

        // The optional steps need not be done for setup to be complete.
        $this->assertFalse($steps['imagery']['done']);
        $this->assertTrue($this->plugin->onboarding->progress()['complete']);

        // Undo a step and it shows as undone again.
        $this->plugin->store->deleteDocument('guide', 'voice');
        $this->assertFalse($this->plugin->onboarding->progress()['complete']);
    }

    public function testTheWizardSavesTheSectionsChosen(): void
    {
        $this->makeArticlesSection();

        $this->action('ghostwriter/setup/sections', ['sections' => ['press'], 'voiceSections' => ['articles', 'press']]);

        // Every section ticked is the same as none chosen: all of them.
        $this->assertSame(['press'], $this->plugin->getSettings()->sections);
        $this->assertSame([], $this->plugin->getSettings()->voiceSections);

        $details = $this->plugin->onboarding->details();
        $this->assertSame(['articles' => false, 'press' => true], array_column($details['sections'], 'writeFor', 'handle'));
        $this->assertSame(['press'], array_column($details['kinds'], 'handle'));

        $this->assertSame(422, $this->action('ghostwriter/setup/sections', ['sections' => []])['status']);

        // Plugin settings are an admin's to change.
        $this->signIn();
        $this->assertSame(403, $this->action('ghostwriter/setup/sections', ['sections' => ['articles']])['status']);
    }

    public function testTheDashboardCardCanBeHidden(): void
    {
        $this->assertFalse($this->action('ghostwriter/dashboard/index', method: 'GET')['data']['variables']['setup']['hidden']);

        $this->action('ghostwriter/setup/hide', ['hidden' => 1]);

        $this->assertTrue($this->action('ghostwriter/dashboard/index', method: 'GET')['data']['variables']['setup']['hidden']);
    }

    public function testGetStartedIsSwitchedFromTheSettings(): void
    {
        $this->signIn(admin: true);

        $this->assertTrue(Craft::$app->getPlugins()->savePluginSettings($this->plugin, ['showGetStarted' => false]));
        $this->assertTrue($this->plugin->onboarding->hidden());

        // The switch is Ghostwriter's own state, not project config.
        $this->assertArrayNotHasKey('showGetStarted', Craft::$app->getProjectConfig()->get('plugins.ghostwriter.settings') ?? []);

        $this->assertTrue(Craft::$app->getPlugins()->savePluginSettings($this->plugin, ['showGetStarted' => true]));
        $this->assertFalse($this->plugin->onboarding->hidden());
    }

    public function testTheScreenRenders(): void
    {
        $response = $this->action('ghostwriter/setup/show', method: 'GET');

        $html = Craft::$app->getView()->renderPageTemplate($response['data']['template'], $response['data']['variables'], View::TEMPLATE_MODE_CP);

        $this->assertStringContainsString('new Ghostwriter.SetupScreen', $html);
        $this->assertStringContainsString('Learn your voice', $html);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function steps(): array
    {
        return array_column($this->plugin->onboarding->steps(), null, 'key');
    }
}
