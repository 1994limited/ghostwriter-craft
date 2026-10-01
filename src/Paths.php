<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\helpers\FileHelper;
use yii\base\Component;

/**
 * Where Ghostwriter keeps things on disk. Two places, for two kinds of file:
 *
 *   guides    voice.md, imagery.md, types/*.yaml, ideas.yaml and any
 *             overridden prompts. Content the project versions.
 *   storage   sessions and the status of jobs in flight. Working state.
 *
 * No database tables are needed.
 */
class Paths extends Component
{
    public function guides(string $file = ''): string
    {
        return $this->join(Plugin::getInstance()->getSettings()->guidesPath, $file);
    }

    public function storage(string $file = ''): string
    {
        return $this->join(Plugin::getInstance()->getSettings()->storagePath, $file);
    }

    /**
     * A prompt's text. A project can override any prompt by putting its own
     * copy in the guides folder under prompts/.
     */
    public function prompt(string $name): string
    {
        $overridden = $this->guides("prompts/{$name}.md");
        $file = is_file($overridden) ? $overridden : __DIR__ . "/prompts/{$name}.md";

        return trim((string) file_get_contents($file));
    }

    /**
     * Write a file, creating its folder first.
     */
    public function write(string $path, string $contents): void
    {
        FileHelper::createDirectory(dirname($path));
        FileHelper::writeToFile($path, $contents);
    }

    private function join(string $base, string $file): string
    {
        $base = rtrim(FileHelper::normalizePath(Craft::getAlias($base)), '/');

        return $file === '' ? $base : $base . '/' . ltrim($file, '/');
    }
}
