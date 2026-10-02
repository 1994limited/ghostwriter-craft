<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use nineteenninetyfour\ghostwriter\widgets\GhostwriterWidget;

class PluginTest extends TestCase
{
    public function testThePluginIsInstalledWithItsDefaults(): void
    {
        $this->assertInstanceOf(Plugin::class, Plugin::getInstance());
        $this->assertSame('anthropic', $this->plugin->getSettings()->provider);
        $this->assertStringStartsWith($this->workspace, $this->plugin->paths->guides('voice.md'));
    }

    public function testSettingsFromTheFormAreTidiedAndChecked(): void
    {
        Craft::$app->getPlugins()->savePluginSettings($this->plugin, [
            'provider' => 'openai',
            'model' => '',
            'imageProvider' => '',
            'sections' => ['', 'articles'],
            'voiceSections' => '*',
        ]);

        $settings = $this->plugin->getSettings();

        $this->assertSame('openai', $settings->provider);
        $this->assertNull($settings->model);
        $this->assertNull($settings->imageProvider);
        $this->assertSame(['articles'], $settings->sections);
        $this->assertSame([], $settings->voiceSections);

        $this->assertFalse(Craft::$app->getPlugins()->savePluginSettings($this->plugin, ['provider' => 'someone-else']));
    }

    public function testTheDashboardWidgetShowsWhatIsGoingOn(): void
    {
        $this->assertContains(GhostwriterWidget::class, Craft::$app->getDashboard()->getAllWidgetTypes());

        $this->signIn(permitted: false);
        $this->assertFalse(GhostwriterWidget::isSelectable());
        $this->assertNull((new GhostwriterWidget())->getBodyHtml());

        $this->signIn();
        $this->assertTrue(GhostwriterWidget::isSelectable());

        $html = (string) (new GhostwriterWidget())->getBodyHtml();

        $this->assertStringContainsString('Get started', $html);
        $this->assertStringContainsString('Nothing being written right now.', $html);
        $this->assertStringContainsString('ideas waiting', $html);
    }

    public function testTheMenuItemSitsWithThePlugins(): void
    {
        $this->signIn();

        $nav = Craft::$app->getView()->getTwig()->getGlobals()['craft']->cp->nav();
        $labels = array_column($nav, 'label');

        $this->assertLessThan(array_search('Utilities', $labels, true) ?: PHP_INT_MAX, array_search('Ghostwriter', $labels, true));

        // Ghostwriter's home is the Overview, so it is not mistaken for Craft's own Dashboard.
        $ours = $nav[array_search('Ghostwriter', $labels, true)];
        $this->assertSame('Overview', $ours['subnav']['overview']['label']);
        $this->assertNotContains('Dashboard', array_column($ours['subnav'], 'label'));
    }

        public function testThereIsOnePermission(): void
    {
        $groups = Craft::$app->getUserPermissions()->getAllPermissions();
        $ours = array_values(array_filter($groups, fn(array $group) => $group['heading'] === 'Ghostwriter'));

        $this->assertSame([Plugin::PERMISSION => ['label' => 'Use Ghostwriter']], $ours[0]['permissions']);
        $this->assertSame([], array_filter($groups, fn(array $group) => isset($group['permissions']['accessPlugin-ghostwriter'])));
    }

    public function testAPromptCanBeOverriddenByTheProject(): void
    {
        $this->assertStringStartsWith('You are an editor who writes house style guides.', $this->plugin->paths->prompt('voice-analyst'));

        \craft\helpers\FileHelper::writeToFile($this->plugin->paths->guides('prompts/voice-analyst.md'), "Our own instructions.\n");

        $this->assertSame('Our own instructions.', $this->plugin->paths->prompt('voice-analyst'));

        // An override copied from core keeps its placeholders, filled in with Craft's words.
        \craft\helpers\FileHelper::writeToFile($this->plugin->paths->guides('prompts/planner.md'), "Plan new [[items]] for the [[place]].\n");

        $this->assertSame('Plan new entries for the website.', $this->plugin->paths->prompt('planner'));
    }
}
