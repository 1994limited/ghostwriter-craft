<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\web\View;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

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
        $this->assertSame('Set up Claude (Anthropic) in Connections (or set ANTHROPIC_API_KEY in .env), then reload this page.', $steps['key']['detail']);
        $this->assertTrue($steps['sections']['done']);
        $this->assertSame('Writing for every section: Press.', $steps['sections']['detail']);
        $this->assertSame(['type' => 'post', 'label' => 'Write the voice guide', 'route' => 'voice/scan', 'data' => [], 'needsKey' => true], $steps['voice']['action']);
        $this->assertTrue($steps['imagery']['optional']);
        // Only the five steps setup needs are counted.
        $this->assertSame(['done' => 1, 'total' => 5, 'complete' => false, 'hidden' => false], $this->plugin->onboarding->progress());

        // Doing each thing ticks it off.
        $this->plugin->providers->keys['anthropic'] = 'test-key';
        $this->plugin->domain->saveGuide(Guide::VOICE, '# Tone of voice');
        $this->plugin->types->changeSuggestions('press', fn(KindSuggestions $state) => $state->store([['title' => 'Coverage', 'description' => '', 'why' => '', 'examples' => [1, 2], 'entryType' => null]], 2));

        $steps = $this->steps();
        $this->assertTrue($steps['key']['done']);
        $this->assertSame('Writing with Claude (Anthropic).', $steps['key']['detail']);
        $this->assertTrue($steps['voice']['done']);
        $this->assertSame('ghostwriter/voice', substr($steps['voice']['action']['url'], -17));

        // Suggestions waiting are not the same as kinds learned.
        $this->assertFalse($steps['kinds']['done']);
        $this->assertSame('1 suggestion is waiting on the Overview.', $steps['kinds']['detail']);

        $this->plugin->types->save($this->plugin->types->make('coverage', ['title' => 'Coverage', 'section' => 'press', 'questions' => [['handle' => 'q', 'label' => 'Q']]]));
        $this->plugin->sessions->save(Session::start(Format::Craft, ContentType::GENERIC . 'press', [], \Craft::$app->getUser()->getId()));

        $steps = $this->steps();
        $this->assertTrue($steps['kinds']['done']);
        $this->assertTrue($steps['write']['done']);

        // The optional steps need not be done for setup to be complete,
        // and the count reaches the end without them.
        $this->assertFalse($steps['imagery']['done']);
        $this->assertSame(['done' => 5, 'total' => 5, 'complete' => true, 'hidden' => false], $this->plugin->onboarding->progress());

        // Undo a step and it shows as undone again.
        $this->plugin->store->deleteDocument('guide', 'voice');
        $this->assertFalse($this->plugin->onboarding->progress()['complete']);
    }

    public function testAKeyForAnotherProviderIsPointedOut(): void
    {
        $this->unfake();
        $this->plugin->providers->keys['anthropic'] = null;

        $this->assertNull($this->plugin->onboarding->details()['otherKey']);

        $this->plugin->providers->keys['gemini'] = 'test-key';
        $this->assertSame('Gemini (Google)', $this->plugin->onboarding->details()['otherKey']);

        // Not once the chosen provider has its own.
        $this->plugin->providers->keys['anthropic'] = 'test-key';
        $this->assertNull($this->plugin->onboarding->details()['otherKey']);
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

    public function testOnlyAnAdminCanHideOrShowGetStarted(): void
    {
        $this->signIn();

        $this->assertSame(403, $this->action('ghostwriter/setup/hide', ['hidden' => 1])['status']);
        $this->assertFalse($this->plugin->onboarding->hidden());

        $html = $this->dashboard();
        $this->assertStringContainsString('Get started · ', $html);
        $this->assertStringNotContainsString('data-hide-setup', $html);
        $this->assertFalse($this->plugin->onboarding->details()['canHide']);

        // And with it hidden, there is no link to bring it back either.
        $this->plugin->onboarding->hide();
        $this->assertStringNotContainsString('data-show-setup', $this->dashboard());

        $this->signIn(admin: true);
        $this->assertStringContainsString('data-show-setup', $this->dashboard());
        $this->assertSame(200, $this->action('ghostwriter/setup/hide', ['hidden' => 0])['status']);
        $this->assertFalse($this->plugin->onboarding->hidden());
    }

    public function testOnceSetUpTheCardSaysSoUntilItIsHidden(): void
    {
        $this->plugin->domain->saveGuide(Guide::VOICE, '# Tone of voice');
        $this->plugin->types->save($this->plugin->types->make('coverage', ['title' => 'Coverage', 'section' => 'press', 'questions' => [['handle' => 'q', 'label' => 'Q']]]));
        $this->plugin->sessions->save(Session::start(Format::Craft, ContentType::GENERIC . 'press', [], \Craft::$app->getUser()->getId()));

        $html = $this->dashboard();
        $this->assertStringContainsString('You’re set up', $html);
        $this->assertStringContainsString('data-hide-setup', $html);
        $this->assertStringNotContainsString('Get started · ', $html);

        $this->plugin->onboarding->hide();
        $this->assertStringNotContainsString('You’re set up', $this->dashboard());
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

    public function testTheWidgetWithoutAKeyStillLeadsIntoGhostwriter(): void
    {
        $this->unfake();
        $this->plugin->providers->keys['anthropic'] = null;

        $html = $this->widget();

        // A warning, not a wall: Get started and the way in stay, and
        // Write something shows, switched off.
        $this->assertStringContainsString('Ghostwriter has no API key yet.', $html);
        $this->assertStringContainsString('Get started · ', $html);
        $this->assertStringContainsString('Next: Connect a model', $html);
        $this->assertStringContainsString('#step-1', $html);
        $this->assertStringContainsString('Open Ghostwriter', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*disabled[^>]*>Write something</', $html);

        $this->plugin->providers->keys['anthropic'] = 'test-key';
        $this->assertStringNotContainsString('no API key', $this->widget());
        $this->assertStringContainsString('menubtn">Write something', $this->widget());
    }

    public function testGetStartedCanBeBroughtBackOnceSetupIsComplete(): void
    {
        $this->plugin->domain->saveGuide(Guide::VOICE, '# Tone of voice');
        $this->plugin->types->save($this->plugin->types->make('coverage', ['title' => 'Coverage', 'section' => 'press', 'questions' => [['handle' => 'q', 'label' => 'Q']]]));
        $this->plugin->sessions->save(Session::start(Format::Craft, ContentType::GENERIC . 'press', [], \Craft::$app->getUser()->getId()));
        $this->assertTrue($this->plugin->onboarding->progress()['complete']);

        $this->plugin->onboarding->hide();

        $this->assertStringContainsString('data-show-setup>Show Get started<', $this->dashboard());
    }

    public function testTheScreenRenders(): void
    {
        $response = $this->action('ghostwriter/setup/show', method: 'GET');

        $html = Craft::$app->getView()->renderPageTemplate($response['data']['template'], $response['data']['variables'], View::TEMPLATE_MODE_CP);

        $this->assertStringContainsString('new Ghostwriter.SetupScreen', $html);
        $this->assertStringContainsString('Learn your voice', $html);
    }

    private function widget(): string
    {
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        return (string) (new \nineteenninetyfour\ghostwriter\widgets\GhostwriterWidget())->getBodyHtml();
    }

    private function dashboard(): string
    {
        $response = $this->action('ghostwriter/dashboard/index', method: 'GET');

        return Craft::$app->getView()->renderPageTemplate($response['data']['template'], $response['data']['variables'], View::TEMPLATE_MODE_CP);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function steps(): array
    {
        return array_column($this->plugin->onboarding->steps(), null, 'key');
    }
}
