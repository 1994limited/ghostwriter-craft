<?php

namespace nineteenninetyfour\ghostwriter\types;

use Craft;
use craft\models\Section;
use nineteenninetyfour\ghostwriter\Plugin;
use Symfony\Component\Yaml\Yaml;
use yii\base\Component;

/**
 * Kinds of content, kept as YAML in the database, one per kind. They are
 * created by studying a section and are the site's to change after, on
 * their screen in the control panel.
 */
class TypeRepository extends Component
{
    /**
     * @return array<string, ContentType>
     */
    public function all(): array
    {
        $types = [];

        foreach (Plugin::getInstance()->store->documents('type') as $handle => $yaml) {
            $types[$handle] = ContentType::fromArray($handle, (array) Yaml::parse($yaml));
        }

        uasort($types, fn(ContentType $a, ContentType $b) => strcmp($a->title, $b->title));

        return $types;
    }

    public function find(string $handle): ?ContentType
    {
        if (str_starts_with($handle, ContentType::GENERIC)) {
            $section = Craft::$app->getEntries()->getSectionByHandle(substr($handle, strlen(ContentType::GENERIC)));

            return $section ? ContentType::generic($section) : null;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $handle)) {
            return null;
        }

        return $this->all()[$handle] ?? null;
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
            $generic = ContentType::generic($found);
            $types[$generic->handle] = $generic;
        }

        return $types;
    }

    /**
     * @return array<string, ContentType>
     */
    public function forSection(string $section): array
    {
        return array_filter($this->all(), fn(ContentType $type) => $type->section === $section);
    }

    public function save(ContentType $type): ContentType
    {
        Plugin::getInstance()->store->putDocument('type', $type->handle, Yaml::dump($type->toArray(), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        return $type;
    }

    public function delete(ContentType $type): void
    {
        Plugin::getInstance()->store->deleteDocument('type', $type->handle);
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

    /**
     * A handle for a new type, from its name, that no other type has.
     */
    public function handleFor(string $title, string $fallback): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: $fallback;
        $handle = $base;
        $n = 2;

        while (isset($this->all()[$handle])) {
            $handle = $base . '-' . $n++;
        }

        return $handle;
    }

}
