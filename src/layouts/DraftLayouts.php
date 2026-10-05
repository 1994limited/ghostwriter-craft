<?php

namespace nineteenninetyfour\ghostwriter\layouts;

use Closure;
use Craft;
use craft\elements\Entry;
use craft\models\EntryType;
use InvalidArgumentException;
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
use NineteenNinetyFour\Ghostwriter\Core\Seo\LinkContext;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SearchSection;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Exceptions\ProviderException;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\seo\HeadingProfiles;
use nineteenninetyfour\ghostwriter\seo\MetaContexts;
use nineteenninetyfour\ghostwriter\suggest\EntryChecks;
use Throwable;

/**
 * Layouts and extras on a piece, for Craft: core's SessionLayouts with the
 * section's schema, pattern and entries (page preview design §5, §6.2).
 * There is no setting: every first draft is laid out (decision 7).
 *
 * - After the writer's turn, afterWriter(): extras read, unit ids carried,
 *   any address the writer made up turned into a link to choose (core's
 *   LinkGuard), and on the first draft the SEO pass's links to the site's
 *   other pages (two calls; the panel says "Checking headings and
 *   links…" meanwhile), then one call to the layout planner.
 * - Every writer turn also gets what the SEO pass needs for the search
 *   title, description and address (MetaContexts); the Text tab's Search
 *   section is search(), its edits editSearch() and its Try again
 *   retrySearch() (one call).
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
    /** How long the SEO pass's link calls are shown as under way, at most. */
    public const CHECKING_SECONDS = 600;

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
    public function afterWriter(Session $session, ?string $before, TaggedResponse $response, Conversation $conversation, WriterContext $writer, ?Closure $progress = null): Usage
    {
        $site = $this->context($session);

        return $site === null ? new Usage() : $this->core()->afterWriter($session, $before, $response, $conversation, $writer, $this->withLinks($site, $session, $writer), $progress);
    }

    /**
     * The context with what the SEO pass needs (SEO layer §7, §9, §10):
     *
     * - to link a draft to the site's other pages: the link index (every
     *   routable page, decision 9), CKEditor's links
     *   (`{entry:12@1:url||/address}`), and the entry's section, site and
     *   language. Given on every writer turn, so core's LinkGuard also
     *   keeps the writer from making up an address; only a first draft is
     *   linked. Without it (it can't be told where the page is going) the
     *   draft goes on with no links.
     * - to write its search title, description and address: MetaContexts'
     *   (the entry's SEO fields, what Ghostwriter wrote before, the slug).
     *   Without it, none are written.
     */
    public function withLinks(LayoutContext $site, Session $session, WriterContext $writer): LayoutContext
    {
        $links = null;

        try {
            $plugin = Plugin::getInstance();
            $type = $plugin->types->find($session->kind)?->forSession($session);

            if ($type !== null) {
                $siteId = $session->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
                $id = $session->source ?? $session->recordId;
                $entry = is_numeric($id) ? Entry::find()->id((int) $id)->siteId($siteId)->drafts(null)->status(null)->one() : null;
                $language = Craft::$app->getSites()->getSiteById($siteId, true)?->language ?? Craft::$app->language;

                $links = new LinkContext(
                    $plugin->linkIndex,
                    Gaps::links(),
                    $type->group,
                    $siteId,
                    $entry instanceof Entry && $entry->getSection() !== null ? EntryChecks::ref($entry) : null,
                    $writer->kind,
                    $writer->voice,
                    $language,
                );
            }
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't get ready to link the draft to the site's other pages: {$exception->getMessage()}", 'ghostwriter');
        }

        $meta = (new MetaContexts())->for($session, $writer->kind, $writer->voice, $site->schema);

        if ($links === null && $meta === null) {
            return $site;
        }

        return new LayoutContext($site->schema, $site->pattern, $site->entries, $site->defaults, $site->exampleIds, $site->profile, $links, $meta);
    }

    /**
     * The context for the Search section's own changes (an edit, Try
     * again): the piece's schema with MetaContexts', no links. Null when
     * the piece's section is gone.
     */
    public function searchContext(Session $session): ?LayoutContext
    {
        $site = $this->context($session);
        $meta = $site === null ? null : (new MetaContexts())->for($session, schema: $site->schema);

        return $site === null ? null : new LayoutContext($site->schema, $site->pattern, $site->entries, $site->defaults, $site->exampleIds, $site->profile, null, $meta);
    }

    /**
     * The Text tab's Search section (SEO layer §9.5, decision 21), as core
     * gives it (Seo\SearchSection): the SEO title, description and
     * address rows, each null where the page has none. Null when there is
     * no draft, or the page has neither SEO fields nor an address.
     *
     * @return array{title: array<string, mixed>|null, description: array<string, mixed>|null, address: array<string, mixed>|null, fields: bool}|null
     */
    public function search(Session $session): ?array
    {
        if ($session->draft === null || trim($session->draft) === '') {
            return null;
        }

        try {
            $meta = (new MetaContexts())->for($session, schema: $this->schema($session), taken: false);

            if ($meta === null) {
                return null;
            }

            $search = (new SearchSection())->of($session, $meta);

            return $search['fields'] || $search['address'] !== null ? $search : null;
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't show the search title and description: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }
    }

    /**
     * An edit in the Search section: the SEO title (empty: use the page
     * title again), the description, or the address (empty: made from the
     * title again). The editor's from then on. No model.
     *
     * @throws InvalidArgumentException when the piece's section is gone.
     */
    public function editSearch(Session $session, string $role, string $text): void
    {
        $site = $this->searchContext($session) ?? throw new InvalidArgumentException('This piece’s section is no longer there.');

        (new SeoPass(logger: Plugin::getInstance()->studio->logger ?? new CraftLogger()))->editMeta($session, $role, $text, $site);
    }

    /**
     * "Try again" in the Search section: one `seo-editor` call for another
     * title and description (core's SeoPass::retryMeta()). Its tokens are
     * added to the session's usage.
     *
     * @throws ProviderException when the call fails, for the panel to say so.
     */
    public function retrySearch(Session $session): Usage
    {
        $plugin = Plugin::getInstance();
        $site = $this->searchContext($session);

        if ($site === null || $site->meta === null) {
            return new Usage();
        }

        return (new SeoPass(logger: $plugin->studio->logger ?? new CraftLogger(), studio: $plugin->studio->core()))->retryMeta($session, $site);
    }

    // -- Try again in the Search section, under way or failed -------------

    /** Marked from the click until the call is back. */
    public static function searching(string $sessionId, bool $on = true): void
    {
        $cache = Craft::$app->getCache();
        $on ? $cache->set(self::searchKey($sessionId, 'busy'), true, self::CHECKING_SECONDS) : $cache->delete(self::searchKey($sessionId, 'busy'));
    }

    /** Whether Try again is under way on this piece ("Writing another…"); only while the piece is working. */
    public static function isSearching(Session $session): bool
    {
        return $session->isWorking() && (bool) Craft::$app->getCache()->get(self::searchKey($session->id, 'busy'));
    }

    /** Try again's call failed (or worked: false), for the Search section to say so once. */
    public static function searchFailed(string $sessionId, bool $failed = true): void
    {
        $cache = Craft::$app->getCache();
        $failed ? $cache->set(self::searchKey($sessionId, 'failed'), true, 3600) : $cache->delete(self::searchKey($sessionId, 'failed'));
    }

    public static function hasSearchFailed(Session $session): bool
    {
        return (bool) Craft::$app->getCache()->get(self::searchKey($session->id, 'failed'));
    }

    private static function searchKey(string $sessionId, string $what): string
    {
        return "ghostwriter:seo-search-{$what}:{$sessionId}";
    }

    /**
     * "Remove link" on a link Ghostwriter added (the Text tab's popover):
     * the words stay, the link goes from the draft and its layouts, and
     * the writer won't put it back. No model.
     *
     * @throws InvalidArgumentException when the draft has no such link.
     */
    public function removeLink(Session $session, string $href): void
    {
        $site = $this->context($session) ?? throw new InvalidArgumentException('This piece’s section is no longer there.');

        if (!$this->core()->removeLink($session, $href, $site)) {
            throw new InvalidArgumentException('That link isn’t in the draft any more.');
        }
    }

    // -- The SEO pass's links on a first draft, under way --------------------

    /** Marked from the moment a first draft is in until its links are. */
    public static function checking(string $sessionId): void
    {
        Craft::$app->getCache()->set(self::checkingKey($sessionId), true, self::CHECKING_SECONDS);
    }

    public static function checked(string $sessionId): void
    {
        Craft::$app->getCache()->delete(self::checkingKey($sessionId));
    }

    /**
     * Whether the SEO pass is still linking this piece's first draft: the
     * panel says "Checking headings and links…" and holds the draft. Only
     * while the piece is working, so a mark left by a job that died never
     * holds it.
     */
    public static function isChecking(Session $session): bool
    {
        return $session->isWorking() && (bool) Craft::$app->getCache()->get(self::checkingKey($session->id));
    }

    private static function checkingKey(string $sessionId): string
    {
        return "ghostwriter:seo-checking:{$sessionId}";
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
                // With the search meta, so the address follows a title changed by hand.
                $meta = (new MetaContexts())->for($session, schema: $site->schema);
                $site = $meta === null ? $site : new LayoutContext($site->schema, $site->pattern, $site->entries, $site->defaults, $site->exampleIds, $site->profile, null, $meta);
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
