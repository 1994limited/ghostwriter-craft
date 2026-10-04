<?php

namespace nineteenninetyfour\ghostwriter\layouts;

use Craft;
use craft\models\EntryType;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Usage;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\LayoutContext;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanBlock;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\SessionLayouts;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Transform;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Conversation;
use NineteenNinetyFour\Ghostwriter\Core\Studio\WriterContext;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\TaggedResponse;
use nineteenninetyfour\ghostwriter\ai\CraftLogger;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\seo\HeadingProfiles;
use Throwable;

/**
 * Layouts and extras on a piece, for Craft: core's SessionLayouts with the
 * section's schema, pattern and entries (page preview design §5, §6.2).
 * There is no setting: every first draft is laid out (decision 7).
 *
 * - After the writer's turn, afterWriter(): extras read, unit ids carried,
 *   and on the first draft one call to the layout planner.
 * - After any other change to the draft, afterEdit(): no model.
 * - draftData(): the chosen layout's draft data, for "Use this draft",
 *   the Preview and the Blocks and Text views. The session's draft stays
 *   the writer's text; a layout is applied by arranging it.
 *
 * Everything is stored on the session, so it is shared by everyone on the
 * piece (E7).
 */
class DraftLayouts
{
    /** @var array<string, LayoutContext> Contexts worked out in this request. */
    private array $contexts = [];

    public function core(): SessionLayouts
    {
        $plugin = Plugin::getInstance();

        return new SessionLayouts($plugin->studio->core(), $plugin->layouts->core, $plugin->studio->logger ?? new CraftLogger());
    }

    /**
     * What layouts need to know about where the piece is going: the entry
     * type's schema, and for a new entry the pattern, the entries it was
     * found from, the kind's defaults and the examples the writer was
     * shown. An existing entry is only rewritten, so it has no pattern.
     */
    public function context(Session $session, ?ContentType $type = null): ?LayoutContext
    {
        $plugin = Plugin::getInstance();
        $type ??= $plugin->types->find($session->kind)?->forSession($session);
        $entryType = $type ? $plugin->types->entryType($type) : null;

        if ($type === null || $entryType === null) {
            return null;
        }

        $key = implode('|', [$type->handle, $entryType->handle, $session->isEditing() ? 'edit' : 'new', json_encode($type->examples), json_encode($type->where)]);

        if (isset($this->contexts[$key])) {
            return $this->contexts[$key];
        }

        $schema = (new SchemaReader())->schema($entryType);

        if ($session->isEditing()) {
            return $this->contexts[$key] = new LayoutContext($schema, profile: HeadingProfiles::for($type, $entryType, $schema));
        }

        $study = $plugin->layouts->study($type->group, $schema, $entryType->handle, $type->where, $type->examples);

        // The writer is shown the first two (Pattern::$examples), in order.
        $exampleIds = array_map(fn($entry) => $entry->id, array_slice($study['entries'], 0, 2));

        return $this->contexts[$key] = new LayoutContext($schema, $study['pattern'], $study['entries'], $type->defaults, $exampleIds, HeadingProfiles::for($type, $entryType, $schema, $study['entries']));
    }

    /**
     * The entry type's schema only: enough to arrange a layout, with no
     * entries read. For showing a piece.
     */
    public function schema(Session $session): ?Schema
    {
        $entryType = $this->entryType($session);

        return $entryType ? (new SchemaReader())->schema($entryType) : null;
    }

    /**
     * After the writer's turn, on the session as the turn left it. On the
     * first draft this calls the layout planner once. The planner's tokens
     * are added to the session's usage, and returned.
     */
    public function afterWriter(Session $session, ?string $before, TaggedResponse $response, Conversation $conversation, WriterContext $writer): Usage
    {
        $site = $this->context($session);

        return $site === null ? new Usage() : $this->core()->afterWriter($session, $before, $response, $conversation, $writer, $site);
    }

    /**
     * After the draft changed by hand (Edit YAML, a click-to-edit): unit
     * ids carried over, the layouts repaired. No model. A layout that no
     * longer fits is marked stale, never re-planned on its own.
     */
    public function afterEdit(Session $session, ?string $before): void
    {
        if ($session->draft === null) {
            return;
        }

        try {
            $site = $this->context($session);

            if ($site !== null) {
                $this->core()->afterEdit($session, $before, $site);
            }
        } catch (Throwable $exception) {
            // The edit itself is kept; the layouts are as they were.
            Craft::warning("Ghostwriter couldn't bring the layouts up to date: {$exception->getMessage()}", 'ghostwriter');
        }
    }

    /**
     * Whether the planner is at work on this piece: it runs once the
     * writer's draft is in, before the piece is idle again, and when the
     * layouts are refreshed. Either way the last message is Ghostwriter's.
     */
    public static function planning(Session $session): bool
    {
        $last = $session->lastMessage();

        return $session->isWorking() && $session->draft !== null && ($last['role'] ?? null) === 'assistant';
    }

    /**
     * The chosen layout (or the one named), as draft data in the draft's
     * own shape: what "Use this draft" builds, the Preview renders and
     * Blocks and Text show. The writer's layout is the draft itself.
     *
     * @return array<string, mixed>
     */
    public function draftData(Session $session, Schema $schema, ?string $planId = null): array
    {
        $plan = $this->plan($session, $planId);

        // The writer's layout too: the SEO pass fits its headings to the
        // template as the profile has it now, which a render may have changed.
        return $this->core()->draftData($session, new LayoutContext($schema, profile: $this->profile($session)), $plan?->id);
    }

    /** How the piece's entry type prints headings; the default when it can't be told. */
    public function profile(Session $session): ?RenderProfile
    {
        try {
            $plugin = Plugin::getInstance();
            $type = $plugin->types->find($session->kind)?->forSession($session);
            $entryType = $type ? $plugin->types->entryType($type) : null;

            return $type && $entryType ? HeadingProfiles::for($type, $entryType, (new SchemaReader())->schema($entryType)) : null;
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't read how the template prints headings: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }
    }

    /**
     * The layout to show: the one named when there is one by that id and
     * it isn't stale, else the chosen one (the writer's when none is).
     */
    public function plan(Session $session, ?string $planId = null): ?Plan
    {
        if ($session->plans === []) {
            return null;
        }

        $plan = $planId === null ? null : Plans::fromArray($session->plans)->get($planId);

        return $plan !== null && !$plan->stale ? $plan : $this->core()->chosen($session);
    }

    /**
     * For a layout other than the writer's, where each piece of writing
     * in its data came from, so it can still be edited where it is shown
     * (C1): a value placed whole from one unit edits that unit's place in
     * the draft; a value from one extra item edits the extra. A value put
     * together from several pieces has no single place to go back to.
     * Null for the writer's layout: its data is the draft.
     *
     * @param array<string, mixed> $arranged The plan's draft data.
     * @return array<string, array{path?: list<string|int>, extra?: string, part?: ?string}>|null By the value's path in the arranged data, joined with "/".
     */
    public function sources(Session $session, Plan $plan, Schema $schema, array $arranged): ?array
    {
        if ($plan->id === Plan::WRITER) {
            return null;
        }

        $draft = Draft::parse((string) $session->draft);
        $units = Units::fromDraft($draft, $schema, Plugin::getInstance()->layouts->core->richText)->restore($session->units);
        $extras = Extras::fromArray($session->extras);
        $sources = [];

        // Fields the plan leaves as they were come straight from the draft.
        $same = function(array $data, array $path) use (&$same, &$sources, $draft): void {
            foreach ($data as $key => $value) {
                $here = [...$path, $key];

                if (is_array($value)) {
                    $same($value, $here);
                } elseif (is_string($value) && self::valueAt($draft->data, $here) === $value) {
                    $sources[implode('/', $here)] = ['path' => $here];
                }
            }
        };
        $same($arranged, []);

        foreach ($plan->fields as $handle => $blocks) {
            $field = $schema->field($handle);

            if ($field === null) {
                continue;
            }

            if ($field->isBuilder()) {
                $this->blockSources($blocks, [$handle], $units, $extras, $draft, $sources);
            } elseif (!Plans::isMarkdown($field) && isset($blocks[0]->placements[0])) {
                $source = $this->placementSource($blocks[0]->placements[0]->from, $blocks[0]->placements[0]->transform, $units, $extras, $draft);

                if ($source !== null) {
                    $sources[$handle] = $source;
                }
            }
        }

        // Only what is really a value in the arranged data.
        return array_filter($sources, fn(array $source, string $at) => is_string(self::valueAt($arranged, array_map(fn($step) => is_numeric($step) ? (int) $step : $step, explode('/', $at)))), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param list<PlanBlock> $blocks
     * @param list<string|int> $path
     * @param array<string, array<string, mixed>> $sources
     */
    private function blockSources(array $blocks, array $path, Units $units, Extras $extras, Draft $draft, array &$sources): void
    {
        foreach (array_values($blocks) as $i => $block) {
            foreach ($block->placements as $placement) {
                $source = $this->placementSource($placement->from, $placement->transform, $units, $extras, $draft);

                if ($source !== null && $placement->rows() === []) {
                    $sources[implode('/', [...$path, $i, ...explode('/', $placement->field)])] = $source;
                }
            }

            foreach ($block->children as $handle => $children) {
                $this->blockSources($children, [...$path, $i, $handle], $units, $extras, $draft, $sources);
            }
        }
    }

    /**
     * Where one placement's words can be edited: one whole unit as it is,
     * or one extra item (or one of its parts).
     *
     * @param list<string> $from
     * @return array{path?: list<string|int>, extra?: string, part?: ?string}|null
     */
    private function placementSource(array $from, Transform $transform, Units $units, Extras $extras, Draft $draft): ?array
    {
        if (count($from) !== 1 || $transform !== Transform::AsIs) {
            return null;
        }

        $ref = $from[0];

        if (preg_match('/^(x\d+\.\d+)(?:\.([a-z_]+))?$/', $ref, $m) === 1) {
            return $extras->item($m[1]) !== null ? ['extra' => $m[1], 'part' => $m[2] ?? null] : null;
        }

        $unit = $units->get($ref);

        if ($unit === null || $unit->part !== null) {
            return null;
        }

        $path = array_map(fn($step) => is_numeric($step) ? (int) $step : $step, explode('.', $unit->path->dotted()));

        return is_string(self::valueAt($draft->data, $path)) ? ['path' => $path] : null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string|int> $path
     */
    private static function valueAt(array $data, array $path): mixed
    {
        $node = $data;

        foreach ($path as $step) {
            if (!is_array($node) || !array_key_exists($step, $node)) {
                return null;
            }

            $node = $node[$step];
        }

        return $node;
    }

    private function entryType(Session $session): ?EntryType
    {
        $plugin = Plugin::getInstance();
        $type = $plugin->types->find($session->kind)?->forSession($session);

        return $type ? $plugin->types->entryType($type) : null;
    }
}
