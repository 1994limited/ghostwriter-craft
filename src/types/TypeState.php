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
        $all = $this->all();
        $all[$section] = ['status' => $status, 'error' => $error];

        Plugin::getInstance()->paths->write($this->path(), (string) json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, array{status: string, error: ?string}>
     */
    private function all(): array
    {
        return is_file($this->path()) ? (array) json_decode((string) file_get_contents($this->path()), true) : [];
    }

    private function path(): string
    {
        return Plugin::getInstance()->paths->storage('types.json');
    }
}
