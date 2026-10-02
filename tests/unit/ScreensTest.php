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
        $html = $this->render('ghostwriter/dashboard/index');
        $this->assertStringContainsString('class="screen-title" title="Overview">Overview</h1>', $html);
        $this->assertStringContainsString('Written', $html);
        $html = $this->render('ghostwriter/voice/show');
        $this->assertStringContainsString('We, to you.', $html);
        $this->assertStringContainsString('new Ghostwriter.GuideScreen', $html);
        $this->assertStringContainsString('Rescan and rewrite', $html);
        $this->assertStringContainsString('Read the site again', $html);
    }

    public function testEachGuideSaysWhatItsButtonDoes(): void
    {
        $this->signIn();

        $voice = $this->render('ghostwriter/voice/show');
        $this->assertStringContainsString('Write the voice guide', $voice);
        $this->assertStringContainsString('Generate from your content', $voice);

        $imagery = $this->render('ghostwriter/imagery/show');
        $this->assertStringContainsString('Describe the images', $imagery);
        $this->assertStringContainsString('Look again and rewrite', $imagery);
        $this->assertStringContainsString('Look at the images again and replace the current guide?', $imagery);
    }

    public function testTheModelIsCheckedAgainstTheProvider(): void
    {
        $settings = $this->plugin->getSettings();

        foreach (['claude-sonnet-5-5' => null, '' => null, 'gpt-6.1-sol' => 'does not look like a Claude (Anthropic) model'] as $model => $warning) {
            $settings->model = $model ?: null;
            $warning === null ? $this->assertNull($settings->modelWarning()) : $this->assertStringContainsString($warning, (string) $settings->modelWarning());
        }

        $settings->provider = 'openai';
        $this->assertNull($settings->modelWarning());

        $settings->provider = 'gemini';
        $html = (fn() => $this->settingsHtml())->call($this->plugin);
        $this->assertStringContainsString('“gpt-6.1-sol” does not look like a Gemini (Google) model.', html_entity_decode($html));
        // The placeholder is core's default for the provider.
        $this->assertStringContainsString('placeholder="gemini-3.8-flash"', $html);
    }

    public function testTheKindEditorKeepsQuestionHandlesOutOfSight(): void
    {
        $this->plugin->types->save(\nineteenninetyfour\ghostwriter\types\ContentType::fromArray('story', ['title' => 'Story', 'section' => 'articles', 'questions' => [['handle' => 'who_for', 'label' => 'Who is it for?']]]));
        $this->signIn();

        $html = $this->render('ghostwriter/types/edit', ['handle' => 'story']);

        $this->assertStringNotContainsString('>Handle<', $html);
        $this->assertStringContainsString('value="who_for"', $html);

        // Renaming the question keeps the handle it travels with.
        $this->action('ghostwriter/types/save', ['handle' => 'story', 'title' => 'Story', 'questions' => [['label' => 'Who reads it?', 'handle' => 'who_for'], ['label' => 'Any numbers?']]]);
        $this->assertSame(['who_for', 'any_numbers'], array_column($this->plugin->types->find('story')->questions, 'handle'));
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

    private function render(string $route, array $params = []): string
    {
        $response = $this->action($route, method: 'GET', params: $params);

        $this->assertSame(200, $response['status'], json_encode($response['data']));

        return Craft::$app->getView()->renderPageTemplate($response['data']['template'], $response['data']['variables'], View::TEMPLATE_MODE_CP);
    }
}
