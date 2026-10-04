<?php

namespace nineteenninetyfour\ghostwriter\http;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extra;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraItem;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\Extras;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\SourceKind;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\PlanOrigin;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plans;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use NineteenNinetyFour\Ghostwriter\Core\Text\DraftPreview;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use Throwable;

/**
 * A piece's layouts and extras for the writing panel: the layout cards,
 * the chosen layout's Blocks and Text, and the Extras section of the Text
 * tab with each item's source and state (page preview design §3).
 */
class LayoutsPresenter
{
    public function __construct(private DraftLayouts $layouts = new DraftLayouts()) {}

    /**
     * The cards: the writer's layout first, then the planner's, each with
     * its name, description, block count and whether it is suggested or
     * needs refreshing. `key` changes whenever what the Preview shows
     * could: another layout chosen, refreshed, or the extras changed.
     *
     * @return array{planning: bool, chosen: string, chosenName: ?string, key: string, plans: list<array<string, mixed>>}
     */
    public function layouts(Session $session): array
    {
        $plans = Plans::fromArray($session->plans);
        $chosen = $session->plans === [] ? null : $this->layouts->core()->chosen($session);
        // What each layout changes against the writer's: words for its chip, and where to point on a switch.
        $schema = count($plans) > 1 ? $this->safeSchema($session) : null;
        $changes = $schema === null ? [] : $this->layouts->core()->changes($session, $schema, $this->layouts->profile($session));

        return [
            'planning' => DraftLayouts::planning($session),
            'chosen' => $chosen?->id ?? Plan::WRITER,
            'chosenName' => $chosen === null ? null : self::name($chosen),
            'key' => substr(sha1((string) json_encode([$session->plans, $session->plan, $session->extras])), 0, 16),
            'plans' => array_map(fn(Plan $plan) => [
                'id' => $plan->id,
                'name' => self::name($plan),
                'description' => $plan->origin === PlanOrigin::Writer ? Craft::t('ghostwriter', $plan->description) : $plan->description,
                'blocks' => $plan->blockCount(),
                'suggested' => $plan->suggested,
                'stale' => $plan->stale,
                'writer' => $plan->origin === PlanOrigin::Writer,
                // Its blocks in order, for a card with no page to show.
                'outline' => array_merge(...array_values($plan->sequences())),
                // "Closing line as a quote": one to three, against the writer's.
                'changes' => $changes[$plan->id]['summary'] ?? [],
                // Where it changed: {field, block, section}, as the preview's map counts them.
                'places' => $changes[$plan->id]['places'] ?? [],
            ], $plans->all()),
        ];
    }

    private function safeSchema(Session $session): ?Schema
    {
        try {
            return $this->layouts->schema($session);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The chosen layout laid out for reading (Blocks and Text), each piece
     * of writing with where an edit to it goes: the draft's own place for
     * words placed whole, the extra for an extra's words. Words a layout
     * put together from several places can't be edited there.
     *
     * @param array<int, array<string, mixed>> $specs
     * @return array<int, array<string, mixed>>
     */
    public function preview(Session $session, Draft $draft, array $specs): array
    {
        $plan = $this->layouts->plan($session);

        if ($plan === null || $plan->id === Plan::WRITER) {
            return (new DraftPreview())->render($draft->data, $specs);
        }

        try {
            $schema = Schema::fromSpecs($specs);
            $data = $this->layouts->draftData($session, $schema, $plan->id);
            $sources = $this->layouts->sources($session, $plan, $schema, $data) ?? [];
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't arrange the layout \"{$plan->id}\": {$exception->getMessage()}", 'ghostwriter');

            return (new DraftPreview())->render($draft->data, $specs);
        }

        return $this->retarget((new DraftPreview())->render($data, $specs), $sources);
    }

    /**
     * The Extras section: one card per extra, each item with its words as
     * the page will say them, its parts, where it came from and whether it
     * still needs the editor.
     *
     * @return list<array<string, mixed>>
     */
    public function extras(Session $session): array
    {
        $extras = Extras::fromArray($session->extras);
        $plan = $session->plans === [] ? null : $this->layouts->core()->chosen($session);
        $used = $plan?->extrasUsed() ?? [];

        return array_map(function(Extra $extra) use ($used, $plan) {
            $inUse = array_values(array_filter(array_map(fn(ExtraItem $item) => $item->id, $extra->items), fn(string $id) => in_array($id, $used, true)));

            return [
                'id' => $extra->id,
                'kind' => $extra->kind->value,
                'label' => self::kindLabel($extra->kind),
                'used' => $inUse !== [],
                'usedLabel' => $plan === null ? null : ($inUse !== []
                    ? Craft::t('ghostwriter', 'Used in {layout}', ['layout' => self::name($plan)])
                    : Craft::t('ghostwriter', 'Not used in {layout}', ['layout' => self::name($plan)])),
                'items' => array_map(fn(ExtraItem $item) => $this->item($item, in_array($item->id, $used, true)), $extra->items),
            ];
        }, $extras->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function item(ExtraItem $item, bool $used): array
    {
        $source = $item->source;
        $count = $item->countLabel();
        $state = $item->state();

        $label = match (true) {
            $count !== null => Gaps::translate($count),
            $source === null => null,
            $source->kind === SourceKind::Brief => Craft::t('ghostwriter', 'from your brief'),
            $source->kind === SourceKind::Answer => $source->ref !== null && is_numeric($source->ref)
                ? Craft::t('ghostwriter', 'from your answer · question {number}', ['number' => $source->ref])
                : Craft::t('ghostwriter', 'from your answer'),
            $source->kind === SourceKind::Draft => Craft::t('ghostwriter', 'from the draft'),
            $source->kind === SourceKind::Conversation => Craft::t('ghostwriter', 'from your message'),
            $source->kind === SourceKind::Editor => Craft::t('ghostwriter', 'your words'),
            $source->kind === SourceKind::Entry => Craft::t('ghostwriter', 'from {title}', ['title' => $source->entryTitle ?? Craft::t('ghostwriter', 'one of your pages')]),
        };

        $url = null;

        if ($source?->kind === SourceKind::Entry && is_numeric($source->entryId)) {
            $url = Entry::find()->id((int) $source->entryId)->status(null)->one()?->getCpEditUrl();
        }

        return [
            'id' => $item->id,
            // As stored, markers and all: the extras list shows a fact to
            // add or a count to check as a chip (core's markers.js).
            'text' => $item->text,
            'parts' => $item->parts,
            'source' => ['kind' => $source?->kind->value, 'label' => $label, 'quote' => $source?->quote, 'url' => $url],
            'state' => $state === null ? null : ['key' => substr($state->key, strlen('gaps.extras.')), 'label' => Gaps::translate($state)],
            'review' => $item->needsReview(),
            'answer' => $item->needsAnswer(),
            'used' => $used,
        ];
    }

    /**
     * Each editable node pointed at where its words really live.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param array<string, array{path?: list<string|int>, extra?: string, part?: ?string}> $sources
     * @return array<int, array<string, mixed>>
     */
    private function retarget(array $nodes, array $sources): array
    {
        foreach ($nodes as &$node) {
            if (!empty($node['editable'])) {
                $source = $sources[implode('/', $node['path'])] ?? null;

                if (isset($source['path'])) {
                    $node['path'] = $source['path'];
                } elseif (isset($source['extra'])) {
                    $node['extra'] = $source['extra'];
                    $node['part'] = $source['part'] ?? null;
                    // A count to check is edited as the page says it.
                    $node['text'] = isset($node['text']) ? Markers::withoutChecks((string) $node['text']) : null;
                } else {
                    $node['editable'] = false;
                    $node['assembled'] = true;
                }
            }

            $kind = $node['kind'] ?? null;

            if ($kind === 'blocks') {
                $node['items'] = array_map(fn(array $block) => ['fields' => $this->retarget($block['fields'] ?? [], $sources)] + $block, $node['items'] ?? []);
            } elseif ($kind === 'rows') {
                $node['items'] = array_map(fn(array $row) => $this->retarget($row, $sources), $node['items'] ?? []);
            } elseif ($kind === 'group') {
                $node['fields'] = $this->retarget($node['fields'] ?? [], $sources);
            }
        }

        unset($node);

        return $nodes;
    }

    /** A layout's name for its card: the writer's in the editor's language. */
    public static function name(Plan $plan): string
    {
        return $plan->origin === PlanOrigin::Writer ? Craft::t('ghostwriter', $plan->name) : $plan->name;
    }

    public static function kindLabel(ExtraKind $kind): string
    {
        return match ($kind) {
            ExtraKind::Stats => Craft::t('ghostwriter', 'Numbers'),
            ExtraKind::Faq => Craft::t('ghostwriter', 'Questions and answers'),
            ExtraKind::PullQuote => Craft::t('ghostwriter', 'Pull quote'),
            ExtraKind::AtAGlance => Craft::t('ghostwriter', 'At a glance'),
            ExtraKind::Caption => Craft::t('ghostwriter', 'Caption'),
            ExtraKind::Cta => Craft::t('ghostwriter', 'Call to action'),
            ExtraKind::Testimonial => Craft::t('ghostwriter', 'Testimonial'),
            ExtraKind::Intro => Craft::t('ghostwriter', 'Intro'),
        };
    }
}
