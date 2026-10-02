<?php

namespace nineteenninetyfour\ghostwriter\domain;

use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\Analysis;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindStore;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;
use Symfony\Component\Yaml\Yaml;

/**
 * Kinds of content as `type` documents (YAML, one per handle), the kinds
 * suggested for each section in the `kinds` state, and whether each section
 * is being studied in the `types` state, both keyed by section.
 *
 * Saving suggestions or an analysis rewrites the one state for every
 * section: hold the `state:kinds` or `state:types` lock around reading and
 * saving it (TypeRepository does).
 */
class DbKindStore implements KindStore
{
    public function all(): array
    {
        $types = [];

        foreach ($this->store()->documents('type') as $handle => $yaml) {
            $types[] = $this->type((string) $handle, (string) $yaml);
        }

        usort($types, fn(ContentType $a, ContentType $b) => strcmp($a->title, $b->title));

        return $types;
    }

    public function find(string $handle): ?ContentType
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $handle)) {
            return null;
        }

        $yaml = $this->store()->document('type', $handle);

        return $yaml === null ? null : $this->type($handle, $yaml);
    }

    public function save(ContentType $type): ContentType
    {
        $this->store()->putDocument('type', $type->handle, Yaml::dump($type->toArray(), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        return $type;
    }

    public function delete(string $handle): void
    {
        $this->store()->deleteDocument('type', $handle);
    }

    public function suggestions(string $group): KindSuggestions
    {
        $all = $this->store()->state('kinds');
        $suggestions = is_array($all[$group] ?? null) ? KindSuggestions::fromArray($all[$group], Format::Craft) : KindSuggestions::empty(Format::Craft);
        $suggestions->changedAt = $this->store()->stateUpdatedAt('kinds');

        return $suggestions;
    }

    public function saveSuggestions(string $group, KindSuggestions $suggestions): void
    {
        $all = $this->store()->state('kinds');
        $all[$group] = $suggestions->toArray();

        $this->store()->putState('kinds', $all);
    }

    public function analysis(string $group): Analysis
    {
        $all = $this->store()->state('types');
        $analysis = Analysis::fromArray(is_array($all[$group] ?? null) ? $all[$group] : []);
        $analysis->changedAt = $this->store()->stateUpdatedAt('types');

        return $analysis;
    }

    public function saveAnalysis(string $group, Analysis $analysis): void
    {
        $all = $this->store()->state('types');
        $all[$group] = $analysis->toArray();

        $this->store()->putState('types', $all);
    }

    private function type(string $handle, string $yaml): ContentType
    {
        return ContentType::fromArray((array) Yaml::parse($yaml), Format::Craft, $handle);
    }

    private function store(): Store
    {
        return Plugin::getInstance()->store;
    }
}
