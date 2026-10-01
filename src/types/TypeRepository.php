<?php

namespace nineteenninetyfour\ghostwriter\types;

use Craft;
use craft\helpers\FileHelper;
use craft\models\Section;
use nineteenninetyfour\ghostwriter\Plugin;
use Symfony\Component\Yaml\Yaml;
use yii\base\Component;

/**
 * Content types are YAML files in the project, one per kind of content, so
 * they are versioned with the site and can be edited by hand. They are
 * created by studying a section and are the project's to change after.
 */
class TypeRepository extends Component
{
    /**
     * @return array<string, ContentType>
     */
    public function all(): array
    {
        $directory = $this->directory();

        if (!is_dir($directory)) {
            return [];
        }

        $types = [];

        foreach (glob($directory . '/*.yaml') ?: [] as $file) {
            $handle = basename($file, '.yaml');
            $types[$handle] = ContentType::fromArray($handle, (array) Yaml::parse((string) file_get_contents($file)));
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
        Plugin::getInstance()->paths->write($this->directory() . '/' . $type->handle . '.yaml', Yaml::dump($type->toArray(), 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        return $type;
    }

    public function delete(ContentType $type): void
    {
        FileHelper::unlink($this->directory() . '/' . $type->handle . '.yaml');
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

    private function directory(): string
    {
        return Plugin::getInstance()->paths->guides('types');
    }
}
