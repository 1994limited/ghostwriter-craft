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

        $labels = array_column(Craft::$app->getView()->getTwig()->getGlobals()['craft']->cp->nav(), 'label');

        $this->assertLessThan(array_search('Utilities', $labels, true) ?: PHP_INT_MAX, array_search('Ghostwriter', $labels, true));
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

        $this->plugin->paths->write($this->plugin->paths->guides('prompts/voice-analyst.md'), "Our own instructions.\n");

        $this->assertSame('Our own instructions.', $this->plugin->paths->prompt('voice-analyst'));
    }
}
