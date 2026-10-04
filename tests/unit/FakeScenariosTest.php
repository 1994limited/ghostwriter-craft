<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Ai\TextRequest;
use nineteenninetyfour\ghostwriter\testing\FakeScenarios;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use RuntimeException;
use yii\queue\ExecEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;

/**
 * The end-to-end tests' scripted replies: only with the folder set, Dev
 * Mode on and a local environment, and for a job only when it was pushed
 * while a scenario played.
 */
class FakeScenariosTest extends TestCase
{
    private string $dir;

    protected function _before(): void
    {
        parent::_before();

        $this->dir = sys_get_temp_dir().'/gw-fake-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/site', 0777, true);
        file_put_contents($this->dir.'/site/write.json', '{"agents":{"writer":[{"text":"First."},{"text":"Second."}]}}');
        FakeScenarios::flush();
        $this->unfake();
    }

    protected function _after(): void
    {
        FakeScenarios::flush();
        putenv(FakeScenarios::ENV);
        unset($_SERVER[FakeScenarios::ENV], $_ENV[FakeScenarios::ENV]);
        @unlink($this->dir.'/site/write.json');
        @rmdir($this->dir.'/site');
        @rmdir($this->dir);

        parent::_after();
    }

    private function folder(?string $dir): void
    {
        if ($dir === null) {
            putenv(FakeScenarios::ENV);
            unset($_SERVER[FakeScenarios::ENV], $_ENV[FakeScenarios::ENV]);

            return;
        }

        putenv(FakeScenarios::ENV.'='.$dir);
        $_SERVER[FakeScenarios::ENV] = $dir;
    }

    public function testItIsOffUnlessTheFolderIsSet(): void
    {
        $this->folder(null);

        $this->assertFalse(FakeScenarios::enabled());
    }

    public function testItIsOffWithoutDevMode(): void
    {
        $this->folder($this->dir);
        $general = Craft::$app->getConfig()->getGeneral();
        $devMode = $general->devMode;
        $general->devMode = false;

        try {
            $this->assertFalse(FakeScenarios::enabled());
        } finally {
            $general->devMode = $devMode;
        }
    }

    public function testAScenarioPlaysInOrder(): void
    {
        $this->folder($this->dir);
        FakeScenarios::play('site/write#t1');
        $providers = $this->plugin->providers;

        $this->assertSame('First.', $providers->text()->text(new TextRequest('writer', '', ''))->text);
        $this->assertSame('Second.', $providers->text()->text(new TextRequest('writer', '', ''))->text);
    }

    public function testAnUnknownScenarioFailsRatherThanCallingAModel(): void
    {
        $this->folder($this->dir);

        $this->expectException(RuntimeException::class);

        FakeScenarios::play('../etc/passwd');
    }

    public function testAJobPushedDuringAScenarioPlaysItAndThenStops(): void
    {
        $this->folder($this->dir);
        $general = Craft::$app->getConfig()->getGeneral();
        $devMode = $general->devMode;
        $general->devMode = true;

        try {
            if (!FakeScenarios::enabled()) {
                $this->markTestSkipped('The test environment is not one the fake runs in.');
            }

            FakeScenarios::register();
            FakeScenarios::play('site/write#j1');
            $queue = Craft::$app->getQueue();
            $queue->trigger(Queue::EVENT_AFTER_PUSH, new PushEvent(['id' => 'e2e-1']));
            FakeScenarios::flush();
            $this->unfake();

            $queue->trigger(Queue::EVENT_BEFORE_EXEC, new ExecEvent(['id' => 'e2e-1']));
            $this->assertSame('First.', $this->plugin->providers->text()->text(new TextRequest('writer', '', ''))->text);

            $queue->trigger(Queue::EVENT_AFTER_EXEC, new ExecEvent(['id' => 'e2e-1']));
            $this->assertFalse($this->plugin->providers->registry()->faked());
        } finally {
            $general->devMode = $devMode;
        }
    }
}
