<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\helpers\FileHelper;
use yii\base\Component;

/**
 * Prompts, and where a project can override them. Everything Ghostwriter
 * writes is kept in the database (see Store); the only files it reads from
 * the project are prompt overrides, in config/ghostwriter/prompts/ by
 * default, which are code and are versioned with it.
 */
class Paths extends Component
{
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

    public function guides(string $file = ''): string
    {
        $base = rtrim(FileHelper::normalizePath(Craft::getAlias(Plugin::getInstance()->getSettings()->guidesPath)), '/');

        return $file === '' ? $base : $base . '/' . ltrim($file, '/');
    }
}
