<?php

namespace nineteenninetyfour\ghostwriter\content;

use Craft;
use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Gathers a representative sample of the site's published writing for the
 * voice analyst to read. Newest entries come first, spread across the
 * sections, and everything is capped so one scan is one affordable request.
 */
class ContentScanner extends Component
{
    /**
     * Sections available to scan, with how many live entries each has.
     *
     * @return array<int, array{handle: string, title: string, entries: int, selected: bool}>
     */
    public function sections(): array
    {
        $selected = Plugin::getInstance()->getSettings()->voiceSections;

        return array_map(fn($section) => [
            'handle' => $section->handle,
            'title' => Craft::t('site', $section->name),
            'entries' => (int) Entry::find()->section($section->handle)->status('live')->count(),
            'selected' => $selected === [] || in_array($section->handle, $selected, true),
        ], Craft::$app->getEntries()->getAllSections());
    }

    /**
     * @param string[]|null $sections Handles to read; null uses the configured set.
     * @return array<int, array{title: string, section: string, url: ?string, text: string}>
     */
    public function samples(?array $sections = null): array
    {
        $settings = Plugin::getInstance()->getSettings();

        // An empty list asked for reads nothing; only the setting's empty
        // list means every section.
        if ($sections === []) {
            return [];
        }

        $handles = $sections ?? $settings->voiceSections;

        if ($handles === []) {
            $handles = array_map(fn($section) => $section->handle, Craft::$app->getEntries()->getAllSections());
        }

        $budget = $settings->voiceMaxChars;
        $samples = [];

        foreach ($this->interleaved($handles, $settings->voiceMaxEntries * 3) as $entry) {
            if (count($samples) >= $settings->voiceMaxEntries || $budget <= 0) {
                break;
            }

            $text = Plugin::getInstance()->prose->fromEntry($entry);

            // Too little to learn a voice from: a contact page, a stub.
            if (mb_strlen($text) < 400) {
                continue;
            }

            $text = mb_substr($text, 0, min($settings->voiceMaxCharsPerEntry, $budget));
            $budget -= mb_strlen($text);

            $samples[] = [
                'title' => (string) $entry->title,
                'section' => (string) $entry->getSection()?->handle,
                'url' => $entry->getUrl(),
                'text' => $text,
            ];
        }

        return $samples;
    }

    /**
     * Newest first from each section, taken one at a time in turn, so a
     * large section cannot crowd out a small one.
     *
     * @param string[] $handles
     * @return Entry[]
     */
    private function interleaved(array $handles, int $perSection): array
    {
        $queues = [];

        foreach ($handles as $handle) {
            if (Craft::$app->getEntries()->getSectionByHandle($handle)) {
                $queues[] = Entry::find()->section($handle)->status('live')->orderBy(['postDate' => SORT_DESC, 'elements.id' => SORT_DESC])->limit($perSection)->all();
            }
        }

        $longest = $queues ? max(array_map('count', $queues)) : 0;
        $ordered = [];

        for ($i = 0; $i < $longest; $i++) {
            foreach ($queues as $queue) {
                if (isset($queue[$i])) {
                    $ordered[] = $queue[$i];
                }
            }
        }

        return $ordered;
    }
}
