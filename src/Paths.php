<?php

namespace nineteenninetyfour\ghostwriter;

use Craft;
use craft\helpers\FileHelper;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\PromptLibrary;
use NineteenNinetyFour\Ghostwriter\Core\Prompts\Vocabulary;
use yii\base\Component;

/**
 * Prompts, and where a project can override them. Everything Ghostwriter
 * writes is kept in the database (see Store); the only files it reads from
 * the project are prompt overrides, in config/ghostwriter/prompts/ by
 * default, which are code and are versioned with it.
 *
 * The prompts themselves ship with ghostwriter-core, in
 * vendor/1994/ghostwriter-core/resources/prompts/.
 */
class Paths extends Component
{
    private ?PromptLibrary $prompts = null;

    /**
     * A prompt's text, with Craft's words (website, section, entry) filled
     * in. A project can override any prompt by putting its own copy in the
     * guides folder under prompts/.
     */
    public function prompt(string $name): string
    {
        return $this->prompts()->get($name);
    }

    public function prompts(): PromptLibrary
    {
        return $this->prompts ??= new PromptLibrary(Vocabulary::craft(), function(string $name): ?string {
            $file = $this->guides("prompts/{$name}.md");

            return is_file($file) ? (string) file_get_contents($file) : null;
        });
    }

    public function guides(string $file = ''): string
    {
        $base = rtrim(FileHelper::normalizePath(Craft::getAlias(Plugin::getInstance()->getSettings()->guidesPath)), '/');

        return $file === '' ? $base : $base . '/' . ltrim($file, '/');
    }
}
