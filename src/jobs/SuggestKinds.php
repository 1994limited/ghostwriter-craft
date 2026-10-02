<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;

/**
 * Reads what each section holds and suggests the kinds of content in it,
 * for a person to look over and learn the ones worth having.
 */
class SuggestKinds extends Job
{
    /** @var array<int, string> Section handles. */
    public array $sections = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();

        foreach ($this->sections as $handle) {
            $section = Craft::$app->getEntries()->getSectionByHandle($handle);

            if (!$section) {
                continue;
            }

            try {
                $suggestions = $plugin->studio->suggestKinds($section);
                $live = (int) Entry::find()->section($handle)->status('live')->count();

                $plugin->types->changeSuggestions($handle, fn(KindSuggestions $state) => $state->store($suggestions, $live));
            } catch (Throwable $exception) {
                Craft::error($exception, 'ghostwriter');

                $plugin->types->changeSuggestions($handle, fn(KindSuggestions $state) => $state->fail($exception->getMessage()));
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Looking for kinds of content');
    }
}
