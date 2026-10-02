<?php

namespace nineteenninetyfour\ghostwriter\types;

use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * Kinds of content as Craft sees them: the sections Ghostwriter writes for,
 * the kinds taught for each (core's ContentType, kept by the kind store)
 * with the general one every section has, the kinds suggested for each
 * section and whether a section is being studied.
 */
class TypeRepository extends Component
{
    /**
     * @return array<string, ContentType> By handle, by title.
     */
    public function all(): array
    {
        $types = [];

        foreach (Plugin::getInstance()->kindStore->all() as $type) {
            $types[$type->handle] = $type;
        }

        return $types;
    }

    public function find(string $handle): ?ContentType
    {
        if (str_starts_with($handle, ContentType::GENERIC)) {
            $section = Craft::$app->getEntries()->getSectionByHandle(substr($handle, strlen(ContentType::GENERIC)));

            return $section ? $this->generic($section) : null;
        }

        return Plugin::getInstance()->kindStore->find($handle);
    }

    /**
     * The type every section has without being taught anything.
     */
    public function generic(Section $section): ContentType
    {
        return ContentType::generic(Format::Craft, $section->handle, Craft::t('site', $section->name));
    }

    /**
     * A kind from its definition (title, description, section, entryType,
     * where, defaults, examples, questions, guidance, checklist), in the
     * shape every kind is saved in.
     *
     * @param array<string, mixed> $definition
     */
    public function make(string $handle, array $definition): ContentType
    {
        return ContentType::fromArray(ContentType::fromArray($definition, Format::Craft, $handle)->definition(), Format::Craft, $handle);
    }

    /**
     * Everything that can be written in a section: the kinds it has been
     * taught, then the general one that is always there.
     *
     * @return array<string, ContentType>
     */
    public function offeredFor(string $section): array
    {
        $types = $this->forSection($section);

        if ($found = Craft::$app->getEntries()->getSectionByHandle($section)) {
            $generic = $this->generic($found);
            $types[$generic->handle] = $generic;
        }

        return $types;
    }

    /**
     * @return array<string, ContentType>
     */
    public function forSection(string $section): array
    {
        return array_filter($this->all(), fn(ContentType $type) => $type->group === $section);
    }

    public function save(ContentType $type): ContentType
    {
        return Plugin::getInstance()->kindStore->save($type);
    }

    public function delete(ContentType $type): void
    {
        Plugin::getInstance()->kindStore->delete($type->handle);
    }

    /**
     * A handle for a new type, from its name, that no other type has.
     */
    public function handleFor(string $title, string $fallback): string
    {
        return ContentType::handleFor(Format::Craft, $title, $fallback, array_keys($this->all()));
    }

    /**
     * The sections Ghostwriter writes for: those chosen in its settings, or
     * every section when none is chosen.
     *
     * @return Section[]
     */
    public function sections(): array
    {
        $handles = Plugin::getInstance()->getSettings()->sections;

        return array_values(array_filter(
            Craft::$app->getEntries()->getAllSections(),
            fn(Section $section) => $handles === [] || in_array($section->handle, $handles, true),
        ));
    }

    public function enabled(string $section): bool
    {
        foreach ($this->sections() as $candidate) {
            if ($candidate->handle === $section) {
                return true;
            }
        }

        return false;
    }

    public function section(ContentType $type): ?Section
    {
        return Craft::$app->getEntries()->getSectionByHandle($type->group);
    }

    /**
     * The entry type written: the type's own, or the section's first.
     */
    public function entryType(ContentType $type): ?EntryType
    {
        foreach ($this->section($type)?->getEntryTypes() ?? [] as $candidate) {
            if ($type->variant === null || $candidate->handle === $type->variant) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * What the questionnaire needs.
     *
     * @return array<string, mixed>
     */
    public function questionnaire(ContentType $type): array
    {
        return [
            'handle' => $type->handle,
            'title' => $type->title,
            'description' => $type->description,
            'questions' => $type->questions,
            'examples' => $type->examples,
            'entryType' => $type->variant,
            'generic' => $type->isGeneric(),
        ];
    }

    /**
     * The kinds suggested for a section, with a look that stopped without
     * finishing shown as failed.
     */
    public function suggestions(string $section): KindSuggestions
    {
        $suggestions = Plugin::getInstance()->kindStore->suggestions($section);
        $suggestions->recoverIfStale(Plugin::getInstance()->domain->options());

        return $suggestions;
    }

    /**
     * Change a section's suggestions from what they are now, with nobody
     * else changing any section's in between.
     *
     * @param callable(KindSuggestions): void $change
     */
    public function changeSuggestions(string $section, callable $change): KindSuggestions
    {
        $plugin = Plugin::getInstance();

        return $plugin->lock->run('state:kinds', function() use ($plugin, $section, $change) {
            $suggestions = $this->suggestions($section);
            $change($suggestions);
            $plugin->kindStore->saveSuggestions($section, $suggestions);

            return $suggestions;
        });
    }

    /**
     * The suggestions as a person weighs them: each with why it is worth
     * teaching and the titles of up to three entries it was seen in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function presented(string $section): array
    {
        $suggestions = $this->suggestions($section)->suggestions;
        $ids = array_values(array_unique(array_merge(...array_map(fn(array $kind) => array_slice(array_map('intval', (array) ($kind['examples'] ?? [])), 0, 3), $suggestions ?: [[]]))));
        $titles = [];

        foreach ($ids === [] ? [] : Entry::find()->id($ids)->status(null)->all() as $entry) {
            $titles[(int) $entry->id] = (string) $entry->title;
        }

        return array_map(fn(array $kind) => $kind + [
            'why' => '',
            'exampleTitles' => array_values(array_filter(array_map(fn($id) => $titles[(int) $id] ?? null, array_slice((array) ($kind['examples'] ?? []), 0, 3)))),
        ], $suggestions);
    }

    /**
     * Whether a section should be looked at without being asked: never
     * looked at, or with enough published since the last look.
     */
    public function due(Section $section): bool
    {
        return $this->suggestions($section->handle)->due((int) Entry::find()->section($section->handle)->status('live')->count());
    }

    /**
     * Whether a section is being studied to learn a kind, with a study that
     * stopped without finishing shown as failed.
     */
    public function analysis(string $section): Analysis
    {
        $analysis = Plugin::getInstance()->kindStore->analysis($section);
        $analysis->recoverIfStale(Plugin::getInstance()->domain->options());

        return $analysis;
    }

    /**
     * @param callable(Analysis): void $change
     */
    public function changeAnalysis(string $section, callable $change): Analysis
    {
        $plugin = Plugin::getInstance();

        return $plugin->lock->run('state:types', function() use ($plugin, $section, $change) {
            $analysis = $this->analysis($section);
            $change($analysis);
            $plugin->kindStore->saveAnalysis($section, $analysis);

            return $analysis;
        });
    }
}
