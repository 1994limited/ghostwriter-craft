<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Craft;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * Core's logs (a call finished, was retried or failed) written to Craft's
 * own log under the ghostwriter category. Core never logs prompts, replies
 * or keys.
 */
final class CraftLogger extends AbstractLogger
{
    public const CATEGORY = 'ghostwriter';

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $context = array_map(fn($value) => $value instanceof \Throwable ? $value->getMessage() : $value, $context);
        $text = (string) $message . ($context !== [] ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '');

        match ($level) {
            LogLevel::EMERGENCY, LogLevel::ALERT, LogLevel::CRITICAL, LogLevel::ERROR => Craft::error($text, self::CATEGORY),
            LogLevel::WARNING => Craft::warning($text, self::CATEGORY),
            LogLevel::DEBUG => Craft::debug($text, self::CATEGORY),
            default => Craft::info($text, self::CATEGORY),
        };
    }
}
