<?php

namespace nineteenninetyfour\ghostwriter\drafts;

use InvalidArgumentException;
use nineteenninetyfour\ghostwriter\ai\LenientYaml;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A working draft: the entry's fields written out as YAML, with rich text as
 * markdown and page-builder blocks as a plain list. It is text so that a
 * person can read and edit it, the model can rewrite it, and nothing about
 * it depends on how any one site stores its content.
 */
class Draft
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $raw,
    ) {}

    public static function parse(string $raw): self
    {
        $raw = trim($raw);

        // Models sometimes wrap the whole draft in a code fence.
        $raw = (string) preg_replace('/\A```(?:yaml|yml)?\s*\n(.*)\n```\z/s', '$1', $raw);

        try {
            $data = LenientYaml::parse($raw);
        } catch (ParseException $exception) {
            throw new InvalidArgumentException('The draft is not valid YAML: '.$exception->getMessage());
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidArgumentException('The draft should be a list of fields, starting with the title.');
        }

        if (trim((string) ($data['title'] ?? '')) === '') {
            throw new InvalidArgumentException('The draft needs a title.');
        }

        return new self($data, $raw);
    }

    public function title(): string
    {
        return trim((string) $this->data['title']);
    }

    /**
     * Words of actual writing, not counting field names and block types.
     */
    public function wordCount(): int
    {
        $words = 0;
        $data = $this->data;

        array_walk_recursive($data, function ($value, $key) use (&$words): void {
            if (is_string($value) && $key !== 'type') {
                $words += str_word_count($value);
            }
        });

        return $words;
    }
}
