<?php

namespace nineteenninetyfour\ghostwriter\types;

use craft\elements\Entry;
use craft\models\Section;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\Store;
use nineteenninetyfour\ghostwriter\sessions\Session;
use yii\base\Component;

/**
 * Kinds of content Ghostwriter has suggested for each section, waiting for a
 * person to say which are worth learning. Working state.
 *
 * A section is checked when someone asks, and by itself (when the setting is
 * on) the first time it is seen and again once enough has been published
 * there since, so suggestions keep up with the site without a call on every
 * visit.
 */
class KindSuggestions extends Component
{
    public const IDLE = 'idle';

    public const WORKING = 'working';

    public const FAILED = 'failed';

    /** New live entries since the last check that make another one worthwhile. */
    public const RECHECK_AFTER = 10;

    /**
     * @return array{status: string, error: ?string, checkedAt: ?string, entries: int, suggestions: array<int, array<string, mixed>>, dismissed: array<int, string>}
     */
    public function get(string $section): array
    {
        $state = array_merge(
            ['status' => self::IDLE, 'error' => null, 'checkedAt' => null, 'entries' => 0, 'suggestions' => [], 'dismissed' => []],
            $this->all()[$section] ?? [],
        );

        if ($state['status'] === self::WORKING && Store::isStale(Plugin::getInstance()->store->stateUpdatedAt('kinds'))) {
            $state = array_merge($state, ['status' => self::FAILED, 'error' => Store::STOPPED]);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(string $section, array $changes): void
    {
        Plugin::getInstance()->store->changeState('kinds', fn(array $all) => array_merge($all, [
            $section => array_merge(
                ['status' => self::IDLE, 'error' => null, 'checkedAt' => null, 'entries' => 0, 'suggestions' => [], 'dismissed' => []],
                $all[$section] ?? [],
                $changes,
            ),
        ]));
    }

    /**
     * @param array<int, array<string, mixed>> $suggestions
     */
    public function store(string $section, array $suggestions, int $entries): void
    {
        $this->update($section, [
            'status' => self::IDLE,
            'error' => null,
            'checkedAt' => Session::now(),
            'entries' => $entries,
            'suggestions' => array_values(array_map(fn(array $suggestion) => ['id' => bin2hex(random_bytes(6))] + $suggestion, $suggestions)),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $section, string $id): ?array
    {
        foreach ($this->get($section)['suggestions'] as $suggestion) {
            if ($suggestion['id'] === $id) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * Take a suggestion off the list. Turned down, it is remembered, so it is
     * not suggested again.
     */
    public function remove(string $section, string $id, bool $dismissed = false): void
    {
        $state = $this->get($section);
        $gone = $this->find($section, $id);

        $this->update($section, [
            'suggestions' => array_values(array_filter($state['suggestions'], fn(array $suggestion) => $suggestion['id'] !== $id)),
            'dismissed' => $dismissed && $gone ? array_values(array_unique([...$state['dismissed'], $gone['title']])) : $state['dismissed'],
        ]);
    }

    /**
     * Whether a section should be checked without being asked: never looked
     * at, or with enough published since the last look.
     */
    public function due(Section $section): bool
    {
        $state = $this->get($section->handle);

        if ($state['status'] === self::WORKING || $state['status'] === self::FAILED) {
            return false;
        }

        $live = (int) Entry::find()->section($section->handle)->status('live')->count();

        if ($live < 2) {
            return false;
        }

        return $state['checkedAt'] === null || $live >= $state['entries'] + self::RECHECK_AFTER;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function all(): array
    {
        return Plugin::getInstance()->store->state('kinds');
    }
}
