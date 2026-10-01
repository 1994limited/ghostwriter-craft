<?php

namespace nineteenninetyfour\ghostwriter\voice;

use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;
use yii\base\Component;

/**
 * Working state for the voice guide screen: whether a scan or refinement is
 * running, the last error, and the refine conversation.
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
        $store = Plugin::getInstance()->store;
        $state = array_merge($this->defaults(), $store->state($this->name()));

        if ($state['status'] === self::WORKING && Store::isStale($store->stateUpdatedAt($this->name()))) {
            $state = array_merge($state, ['status' => self::FAILED, 'error' => Store::STOPPED, 'task' => null]);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(array $changes): void
    {
        Plugin::getInstance()->store->changeState($this->name(), fn(array $state) => array_merge($this->defaults(), $state, $changes));
    }

    public function addMessage(string $role, string $content): void
    {
        Plugin::getInstance()->store->changeState($this->name(), fn(array $state) => array_merge($this->defaults(), $state, [
            'messages' => [...($state['messages'] ?? []), ['role' => $role, 'content' => $content]],
        ]));
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

    protected function name(): string
    {
        return 'voice';
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return ['status' => self::IDLE, 'error' => null, 'task' => null, 'messages' => [], 'scanned' => [], 'pending' => []];
    }
}
