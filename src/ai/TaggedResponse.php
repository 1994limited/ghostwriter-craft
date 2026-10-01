<?php

namespace nineteenninetyfour\ghostwriter\ai;

/**
 * The agents answer in two tagged blocks: what they say to the person, and
 * the document or draft they are handing back. Tags are used instead of JSON
 * because a long markdown document survives them untouched, on any provider.
 */
class TaggedResponse
{
    public function __construct(
        public readonly string $reply,
        public readonly ?string $document,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?string $images = null,
    ) {}

    public static function parse(string $text, string $documentTag, int $inputTokens = 0, int $outputTokens = 0): self
    {
        $reply = self::block($text, 'reply');
        $document = self::block($text, $documentTag);

        // A model that ignores the format still said something; show it
        // rather than lose it.
        if ($reply === null) {
            $reply = trim((string) preg_replace('/<('.$documentTag.'|images)>.*?(<\/\1>|\z)/s', '', $text));
        }

        // The writer's requests for images, where it made any.
        return new self($reply, $document, $inputTokens, $outputTokens, self::block($text, 'images'));
    }

    private static function block(string $text, string $tag): ?string
    {
        // The closing tag is optional so a response cut off at the token
        // limit still yields what was written.
        if (! preg_match('/<'.$tag.'>(.*?)(?:<\/'.$tag.'>|\z)/s', $text, $m)) {
            return null;
        }

        $value = trim($m[1]);

        return $value === '' ? null : $value;
    }
}
