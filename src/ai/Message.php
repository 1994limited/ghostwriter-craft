<?php

namespace nineteenninetyfour\ghostwriter\ai;

/**
 * One earlier turn of a conversation: "user" or "assistant".
 */
final class Message
{
    public function __construct(
        public readonly string $role,
        public readonly string $content,
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return Message[]
     */
    public static function list(array $history): array
    {
        return array_map(fn(array $message) => new self((string) $message['role'], (string) $message['content']), array_values($history));
    }
}
