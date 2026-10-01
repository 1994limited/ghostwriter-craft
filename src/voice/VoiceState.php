<?php

namespace nineteenninetyfour\ghostwriter\voice;

use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Working state for the voice guide screen: whether a scan or refinement is
 * running, the last error, and the refine conversation. Kept in storage
 * rather than in the project, because it is not content.
 */
class VoiceState extends Component
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /**
     * @return array{status: string, error: ?string, task: ?string, messages: array<int, array{role: string, content: string}>, scanned: array<int, array{title: string, section: string}>, pending: array<int, array<string, mixed>>}
     */
    public function get(): array
    {
        $defaults = ['status' => self::IDLE, 'error' => null, 'task' => null, 'messages' => [], 'scanned' => [], 'pending' => []];
        $path = $this->path();

        if (!is_file($path)) {
            return $defaults;
        }

        return array_merge($defaults, (array) json_decode((string) file_get_contents($path), true));
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(array $changes): void
    {
        Plugin::getInstance()->paths->write($this->path(), (string) json_encode(array_merge($this->get(), $changes), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function addMessage(string $role, string $content): void
    {
        $messages = $this->get()['messages'];
        $messages[] = ['role' => $role, 'content' => $content];

        $this->update(['messages' => $messages]);
    }

    /**
     * A failure is reported once. After that the screen starts clean, so an
     * old error does not greet every visit.
     */
    public function forgetFailure(): void
    {
        if ($this->get()['status'] === self::FAILED) {
            $this->update(['status' => self::IDLE, 'error' => null, 'task' => null]);
        }
    }

    protected function path(): string
    {
        return Plugin::getInstance()->paths->storage('voice.json');
    }
}
