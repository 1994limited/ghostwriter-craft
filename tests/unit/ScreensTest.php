<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\fields\PlainText;
use craft\web\View;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The control panel screens render, with what they need to show.
 */
class ScreensTest extends TestCase
{
    protected function _before(): void
    {
        parent::_before();

        $this->makeSection('articles', [$this->makeEntryType('article', [$this->makeField(PlainText::class, 'summary')])]);
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);
    }

    public function testTheDashboardAndVoiceScreensRender(): void
    {
        $this->signIn(extra: []);

        $html = $this->render('ghostwriter/dashboard/index');
        $this->assertStringContainsString('Not written yet', $html);
        $this->assertStringContainsString('Articles', $html);

        $this->plugin->voiceGuide->save("# Tone of voice\n\nWe, to you.");
        $html = $this->render('ghostwriter/voice/show');
        $this->assertStringContainsString('We, to you.', $html);
        $this->assertStringContainsString('new Ghostwriter.GuideScreen', $html);
        $this->assertStringContainsString('Rescan and rewrite', $html);
    }

    public function testAMissingKeyIsExplainedOnEveryScreen(): void
    {
        $this->unfake();
        $this->plugin->providers->keys['anthropic'] = null;
        $this->signIn();

        $this->assertStringContainsString('Add ANTHROPIC_API_KEY to your .env file', $this->render('ghostwriter/dashboard/index'));
        $this->assertStringContainsString('Add ANTHROPIC_API_KEY to your .env file', $this->render('ghostwriter/voice/show'));
    }

    public function testTheSettingsSayWhichKeysAreSetAndNeverWhatTheyAre(): void
    {
        $this->plugin->providers->keys['openai'] = 'sk-very-secret';

        $html = (fn() => $this->settingsHtml())->call($this->plugin);

        $this->assertStringContainsString('OPENAI_API_KEY', $html);
        $this->assertStringNotContainsString('sk-very-secret', $html);
        $this->assertStringContainsString('Write for these sections', $html);
        $this->assertStringContainsString('Articles', $html);
    }

    private function render(string $route): string
    {
        $response = $this->action($route, method: 'GET');

        $this->assertSame(200, $response['status'], json_encode($response['data']));

        return Craft::$app->getView()->renderPageTemplate($response['data']['template'], $response['data']['variables'], View::TEMPLATE_MODE_CP);
    }
}
