<?php

namespace nineteenninetyfour\ghostwriter\ai;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads YAML written by a model. Models write prose into YAML values, and
 * prose has apostrophes, quotation marks and colons in it, which break a
 * quoted or plain scalar in ways a person would never notice. Strict parsing
 * is tried first; if it fails, the one-line values are re-quoted safely and
 * it is tried again.
 */
class LenientYaml
{
    /**
     * @throws ParseException when the text cannot be read even after repair.
     */
    public static function parse(string $yaml): mixed
    {
        try {
            return Yaml::parse($yaml);
        } catch (ParseException $exception) {
            try {
                return Yaml::parse(self::repair($yaml));
            } catch (ParseException) {
                // The first complaint is about the text as it was written.
                throw $exception;
            }
        }
    }

    /**
     * Re-quote every one-line value that is, or should have been, a quoted
     * string. Block scalars and their indented lines are left alone.
     */
    public static function repair(string $yaml): string
    {
        $lines = self::folded(preg_split('/\R/', $yaml) ?: []);
        $block = null;

        foreach ($lines as $i => $line) {
            $indent = strlen($line) - strlen(ltrim($line, ' '));

            // Inside a block scalar nothing is syntax.
            if ($block !== null) {
                if (trim($line) === '' || $indent > $block) {
                    continue;
                }

                $block = null;
            }

            if (! preg_match('/^(\s*(?:-\s+)*[A-Za-z0-9_.-]+:\s+|\s*-\s+)(\S.*?)\s*$/u', $line, $m)) {
                continue;
            }

            [, $key, $value] = $m;

            if (preg_match('/^[|>][+-]?\d*$/', $value)) {
                $block = strlen($key) - strlen(ltrim($key, ' '));

                continue;
            }

            $quote = $value[0];

            if ($quote === "'" || $quote === '"') {
                // Quoted from end to end: take the text inside. Otherwise the
                // quote is part of the text ("Title" and "Other" both...), and
                // the whole value is quoted as it stands.
                $closed = strlen($value) > 1 && str_ends_with($value, $quote);
                $inner = $closed ? substr($value, 1, -1) : $value;
                $inner = $closed ? ($quote === "'" ? str_replace("''", "'", $inner) : stripcslashes($inner)) : $inner;

                $lines[$i] = $key.self::quoted($inner);
            } elseif (! str_contains('[{&*!|>%@`#"\'', $quote) && ! preg_match('/^-\s/', $value) && (str_contains($value, ': ') || str_contains($value, ' #'))) {
                // A plain value holding what YAML would read as a mapping or a comment.
                $lines[$i] = $key.self::quoted($value);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Join a value that was wrapped over several lines back onto its key.
     * YAML allows the wrap, but a colon or a quotation mark in the wrapped
     * part is read as syntax; on one line it can be quoted whole.
     *
     * @param  array<int, string>  $lines
     * @return array<int, string>
     */
    private static function folded(array $lines): array
    {
        $out = [];
        $open = null;
        $block = null;

        foreach ($lines as $line) {
            $indent = strlen($line) - strlen(ltrim($line, ' '));

            if ($block !== null && (trim($line) === '' || $indent > $block)) {
                $out[] = $line;

                continue;
            }

            $block = null;

            // More indented than the key whose value is still open, and not
            // a key or list item of its own: the value carries on here.
            if ($open !== null && trim($line) !== '' && $indent > $open && ! preg_match('/^\s*(?:-\s+|[A-Za-z0-9_.-]+:(?:\s|$))/', $line)) {
                $out[array_key_last($out)] .= ' '.trim($line);

                continue;
            }

            $open = null;
            $out[] = $line;

            if (preg_match('/^(\s*(?:-\s+)*[A-Za-z0-9_.-]+:\s+)(\S.*?)\s*$/u', $line, $m)) {
                if (preg_match('/^[|>][+-]?\d*$/', $m[2])) {
                    $block = $indent;
                } elseif (! preg_match('/^[\[{]/', $m[2])) {
                    $open = $indent;
                }
            }
        }

        return $out;
    }

    private static function quoted(string $value): string
    {
        return '"'.addcslashes($value, '\\"').'"';
    }
}
