<?php

namespace nineteenninetyfour\ghostwriter\types;

use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Whether each section is being studied right now, and the last error.
 */
class TypeState extends Component
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /**
     * @return array{status: string, error: ?string}
     */
    public function get(string $section): array
    {
        return array_merge(['status' => self::IDLE, 'error' => null], $this->all()[$section] ?? []);
    }

    public function set(string $section, string $status, ?string $error = null): void
    {
        Plugin::getInstance()->store->changeState('types', fn(array $all) => array_merge($all, [$section => ['status' => $status, 'error' => $error]]));
    }

    /**
     * @return array<string, array{status: string, error: ?string}>
     */
    private function all(): array
    {
        return Plugin::getInstance()->store->state('types');
    }
}
