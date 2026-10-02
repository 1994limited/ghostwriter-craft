<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\web\View;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use Psr\Log\AbstractLogger;

/**
 * F8: a reply that can't be read is logged with what was wrong with it, and
 * the reply itself only with logReplies on.
 */
class LoggingTest extends TestCase
{
    public function testAnUnreadableReplyIsLoggedWithoutItsTextUnlessAskedFor(): void
    {
        $section = $this->makeSection('news', [$this->makeEntryType('article')]);
        $type = $section->getEntryTypes()[0];

        $this->fake->respond('type-analyst', 'Secret draft text one.', 'Secret draft text two.');

        try {
            $this->plugin->studio->analyseSection($section, $type);
            $this->fail('Expected the analysis to be refused.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('The analysis came back in a form that could not be read. Try again.', $exception->getMessage());
        }

        $logged = $this->logged();
        $this->assertStringContainsString('the type analysis for news could not be read (there was no <type> block); asking again', $logged);
        $this->assertStringContainsString('could not be read again', $logged);
        $this->assertStringNotContainsString('Secret draft text', $logged);

        // On, the whole reply goes in the context.
        $this->plugin->getSettings()->logReplies = true;
        $this->fake->respond('type-analyst', 'Secret draft text one.', 'Secret draft text two.');

        try {
            $this->plugin->studio->analyseSection($section, $type);
        } catch (\InvalidArgumentException) {
        }

        $this->assertStringContainsString('"reply":"Secret draft text two."', $this->logged());
    }

    public function testLogRepliesIsOffByDefaultAndCanComeFromTheEnvironment(): void
    {
        $settings = $this->plugin->getSettings();

        $this->assertFalse($settings->logsReplies());
        $this->assertFalse($this->plugin->studio->core()->options()->logReplies);

        $settings->setAttributes(['logReplies' => '$GHOSTWRITER_LOG_REPLIES'], false);
        $this->assertSame('$GHOSTWRITER_LOG_REPLIES', $settings->logReplies);
        $this->assertTrue($settings->validate(['logReplies']));
        // An unset variable means off.
        $this->assertFalse($settings->logsReplies());

        $_SERVER['GHOSTWRITER_LOG_REPLIES'] = 'true';

        try {
            $this->assertTrue($settings->logsReplies());
            $this->assertTrue($this->plugin->studio->core()->options()->logReplies);
        } finally {
            unset($_SERVER['GHOSTWRITER_LOG_REPLIES']);
        }

        // The settings form sends "1" or "0".
        $settings->setAttributes(['logReplies' => '1'], false);
        $this->assertTrue($settings->logReplies);
        $settings->setAttributes(['logReplies' => '0'], false);
        $this->assertFalse($settings->logReplies);

        $settings->setAttributes(['logReplies' => 'sometimes'], false);
        $this->assertFalse($settings->validate(['logReplies']));
        $settings->logReplies = false;
    }

    public function testTheSettingIsOnTheScreenAndLockedWhenSetInConfig(): void
    {
        $variables = ['settings' => $this->plugin->getSettings(), 'sections' => [], 'keys' => [], 'modelDefaults' => []];

        $html = Craft::$app->getView()->renderTemplate('ghostwriter/_settings', $variables + ['overrides' => []], View::TEMPLATE_MODE_CP);
        $this->assertStringContainsString('Log replies that can’t be read', $html);
        $this->assertStringNotContainsString('Set by <code>logReplies</code>', $html);

        $html = Craft::$app->getView()->renderTemplate('ghostwriter/_settings', $variables + ['overrides' => ['logReplies']], View::TEMPLATE_MODE_CP);
        $this->assertStringContainsString('Set by <code>logReplies</code> in config/ghostwriter.php', $html);
    }

    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    private array $lines = [];

    protected function _before(): void
    {
        parent::_before();

        // Catch what core's Studio logs, as CraftLogger would be handed it.
        $lines = &$this->lines;
        $this->plugin->studio->logger = new class($lines) extends AbstractLogger {
            public function __construct(private array &$lines)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    protected function _after(): void
    {
        $this->plugin->studio->logger = null;
        $this->plugin->getSettings()->logReplies = false;

        parent::_after();
    }

    /**
     * What Ghostwriter logged in this test, as CraftLogger writes it.
     */
    private function logged(): string
    {
        return implode("\n", array_map(fn(array $line) => $line['message'] . ' ' . json_encode($line['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $this->lines));
    }
}
