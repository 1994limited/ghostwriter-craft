<?php

namespace nineteenninetyfour\ghostwriter\testing;

use Craft;
use craft\helpers\App;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Testing\FakeScenario;
use nineteenninetyfour\ghostwriter\Plugin;
use RuntimeException;
use yii\base\Event;
use yii\queue\ExecEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;

/**
 * Scripted model replies for the end-to-end tests (the ghostwriter-e2e
 * repository), so a browser can drive the control panel without spending
 * tokens. Off unless all of these hold:
 *
 * - GHOSTWRITER_FAKE_SCENARIOS is set (in .env) to the folder of scenario
 *   files; it is unset by default;
 * - Dev Mode is on and the environment (CRAFT_ENVIRONMENT) is `dev`,
 *   `local`, `test` or `testing`;
 * - the request names a scenario: the X-Ghostwriter-Fake header, or the
 *   ghostwriter_fake cookie.
 *
 * A request that names one then has core's FakeProvider stand in for every
 * model (FakeScenario). Each job it pushes is remembered against the
 * scenario in the cache, so whoever runs the job (a control panel request
 * or a queue worker) plays the same scenario for it, and goes back to the
 * real providers after it. Requests without the header, such as an
 * editor's own browser on the same site, are untouched.
 */
final class FakeScenarios
{
    /** The environment variable that names the folder. */
    public const ENV = 'GHOSTWRITER_FAKE_SCENARIOS';

    private const ENVIRONMENTS = ['dev', 'local', 'test', 'testing'];

    private const JOB_KEY = 'ghostwriter-fake:job:';

    /** The scenario playing in this process, if any. */
    private static ?string $current = null;

    /** The scenario the web request named, played again after a job run in it. */
    private static ?string $request = null;

    public static function folder(): ?string
    {
        $dir = App::env(self::ENV);

        return is_string($dir) && $dir !== '' ? $dir : null;
    }

    public static function enabled(): bool
    {
        // Craft::$app->env is CRAFT_ENVIRONMENT.
        $environment = strtolower((string) Craft::$app->env);

        return self::folder() !== null
            && Craft::$app->getConfig()->getGeneral()->devMode
            && in_array($environment, self::ENVIRONMENTS, true);
    }

    public static function register(): void
    {
        if (!self::enabled()) {
            return;
        }

        $request = Craft::$app->getRequest();

        if (!$request->getIsConsoleRequest()) {
            $value = $request->getHeaders()->get(FakeScenario::HEADER);
            $value = is_string($value) ? $value : (is_string($_COOKIE[FakeScenario::COOKIE] ?? null) ? $_COOKIE[FakeScenario::COOKIE] : null);

            if ($value !== null && $value !== '') {
                self::play(self::$request = $value);
            }
        }

        Event::on(Queue::class, Queue::EVENT_AFTER_PUSH, function(PushEvent $event): void {
            if (self::$current !== null && $event->id !== null) {
                Craft::$app->getCache()->set(self::JOB_KEY.$event->id, self::$current, 3600);
            }
        });

        Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, function(ExecEvent $event): void {
            $value = $event->id !== null ? Craft::$app->getCache()->get(self::JOB_KEY.$event->id) : false;

            is_string($value) ? self::play($value) : self::stop();
        });

        $after = function(): void {
            self::$request !== null ? self::play(self::$request) : self::stop();
        };

        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, $after);
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, $after);
    }

    /** Forget everything, for tests of this class. */
    public static function flush(): void
    {
        self::$current = self::$request = null;
    }

    /**
     * Stand the scenario in. A name that isn't one fails loudly rather than
     * falling back to the real providers and spending tokens.
     */
    public static function play(string $value): void
    {
        $dir = (string) self::folder();

        if (FakeScenario::path($dir, $value) === null) {
            throw new RuntimeException('Ghostwriter: there is no fake scenario "'.mb_substr($value, 0, 100).'" in '.$dir.'.');
        }

        self::$current = $value;
        Plugin::getInstance()->providers->fake(FakeScenario::load($dir, $value, fn(string $key): int => self::next($key)));
    }

    private static function stop(): void
    {
        if (self::$current === null) {
            return;
        }

        self::$current = null;
        Plugin::getInstance()->providers->unfake();
    }

    /** How many times this counter was asked for before, counted in the cache. */
    private static function next(string $key): int
    {
        $mutex = Craft::$app->getMutex();
        $lock = 'ghostwriter-fake-count';
        $locked = $mutex->acquire($lock, 5);

        try {
            $cache = Craft::$app->getCache();
            $count = (int) ($cache->get($key) ?: 0);
            $cache->set($key, $count + 1, 3600);

            return $count;
        } finally {
            if ($locked) {
                $mutex->release($lock);
            }
        }
    }
}
