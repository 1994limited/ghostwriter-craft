<?php

namespace nineteenninetyfour\ghostwriter\jobs;

use Craft;
use craft\elements\Entry;
use InvalidArgumentException;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\types\TypeState;
use Throwable;

/**
 * Learns a kind of content: reads a section's field layout and existing
 * entries and saves the content type (questions and guidance) used when
 * writing that kind.
 */
class AnalyseSection extends Job
{
    public string $section = '';

    public ?string $title = null;

    /** @var array<int, int> */
    public array $examples = [];

    /**
     * Several kinds to learn one after another, each with its `title` and
     * `examples`, as "Learn all" asks. Empty learns the one above.
     *
     * @var array<int, array{title: ?string, examples: array<int, int>}>
     */
    public array $kinds = [];

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $kinds = $this->kinds ?: [['title' => $this->title, 'examples' => $this->examples]];
        $failed = [];

        foreach ($kinds as $i => $kind) {
            if ($queue) {
                $this->setProgress($queue, $i / count($kinds), $kind['title'] ?? null);
            }

            try {
                $section = Craft::$app->getEntries()->getSectionByHandle($this->section)
                    ?? throw new InvalidArgumentException("The section \"{$this->section}\" does not exist.");

                // A type modelled on chosen entries uses their entry type, which
                // matters on a section with more than one.
                $example = $kind['examples'] ? Entry::find()->id($kind['examples'][0])->status(null)->one() : null;
                $entryType = $example?->getType() ?? ($section->getEntryTypes()[0] ?? null)
                    ?? throw new InvalidArgumentException("The section \"{$this->section}\" has no entry types.");

                $plugin->types->save($plugin->studio->analyseSection($section, $entryType, $kind['title'] ?? null, $kind['examples'] ?? []));
            } catch (Throwable $exception) {
                Craft::error($exception, 'ghostwriter');

                // One kind going wrong does not stop the rest.
                $failed[] = ($kind['title'] ? "{$kind['title']}: " : '') . $exception->getMessage();
            }
        }

        $plugin->typeState->set($this->section, $failed ? TypeState::FAILED : TypeState::IDLE, $failed ? implode(' ', $failed) : null);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('ghostwriter', 'Learning a kind of content');
    }
}
