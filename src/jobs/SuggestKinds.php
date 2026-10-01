<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\types\KindSuggestions;
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

                $plugin->kinds->store($handle, $suggestions, (int) Entry::find()->section($handle)->status('live')->count());
            } catch (Throwable $exception) {
                Craft::error($exception, 'ghostwriter');

                $plugin->kinds->update($handle, ['status' => KindSuggestions::FAILED, 'error' => $exception->getMessage()]);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Looking for kinds of content');
    }
}
