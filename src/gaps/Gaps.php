<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Link;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\UnlicensedStock;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Gap;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapFinder;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapKind;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapReport;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\PublishReadiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Readiness;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\SessionGaps;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Links\CraftLinks;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\Layouts;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\stock\StockView;
use Throwable;

/**
 * "Finish this page" for Craft: core's GapFinder and PublishReadiness over
 * an entry as it stands (a draft, a provisional draft, or the live entry
 * being saved), with Craft's dialects and ports.
 *
 * - In sections Ghostwriter writes for, every detector runs. Elsewhere only
 *   stock previews are looked for, as the stock feature always did: a
 *   `[[name]]` in a site's own content is not ours to block.
 * - Nothing here calls a model. The section's fill rates (which empty
 *   fields are "expected") are learned from its live entries, as the house
 *   style is, and kept for ten minutes; the publish guard doesn't need
 *   them, so it never reads them.
 */
class Gaps extends Component
{
    /** How long a section's fill rates are kept. */
    private const RATES_TTL = 600;

    /**
     * Whether Ghostwriter writes for this entry's section, so all of its
     * gaps are looked for, not only stock previews.
     */
    public static function writesHere(Entry $entry): bool
    {
        $section = $entry->getSection();

        return $section !== null && Plugin::getInstance()->types->enabled($section->handle);
    }

    /**
     * What is unfinished in the entry, for the pill and the guide.
     */
    public function report(Entry $entry): GapReport
    {
        return $this->finder(self::writesHere($entry))->find($this->context($entry, rates: true));
    }

    /**
     * Whether the entry may go live, for the publish guard: one finder run
     * for stock previews and every gap that blocks.
     */
    public function readiness(Entry $entry): Readiness
    {
        $mode = Plugin::getInstance()->getSettings()->onPublish();

        return (new PublishReadiness($this->finder(self::writesHere($entry)), $mode))->check($this->context($entry));
    }

    public function finder(bool $everything = true): GapFinder
    {
        if (!$everything) {
            return new GapFinder([new NamedLibraries()]);
        }

        return new GapFinder(array_map(
            fn($detector) => $detector instanceof UnlicensedStock ? new NamedLibraries($detector) : $detector,
            GapFinder::standard()->detectors(),
        ));
    }

    public function context(Entry $entry, bool $rates = false): GapContext
    {
        $plugin = Plugin::getInstance();
        $specs = (new SchemaReader())->read($entry->getType());
        $schema = Schema::fromSpecs($specs);

        return new GapContext(
            schema: $schema,
            entry: new EntryData((new EntryReader())->read($entry, $specs), (int) $entry->id, (string) $entry->title),
            richText: new HtmlDialect(),
            links: self::links(),
            placeholders: new CraftPlaceholderAssets(),
            assets: new CraftAssetRefs(),
            targets: new CraftLinkTargets((int) $entry->siteId),
            stock: $plugin->stockUsages->ledgerIsEmpty() ? null : $plugin->domain->stock(),
            pattern: $rates ? $this->rates($entry, $schema) : null,
            session: $this->sessionGaps($entry),
        );
    }

    public static function links(): CraftLinks
    {
        return new CraftLinks(hyper: [Layouts::HYPER], link: [Link::class]);
    }

    /**
     * The gap list kept when Ghostwriter last put a draft into this entry.
     */
    public function sessionGaps(Entry $entry): SessionGaps
    {
        $id = (int) ($entry->getCanonicalId() ?? $entry->id);

        foreach (Plugin::getInstance()->sessions->forElement($id) as $session) {
            if ($session->gaps !== []) {
                return SessionGaps::fromSession($session);
            }
        }

        return new SessionGaps();
    }

    /**
     * Everything the guide needs, translated: the count for the pill, each
     * gap with its message, speech label, fixes and where it is in the
     * form, and the stock badge for previews.
     *
     * @return array<string, mixed>
     */
    public function payload(Entry $entry, ?User $user = null): array
    {
        $report = $this->report($entry);
        $stock = [];

        foreach ($report->ofKind(GapKind::StockPreview) as $gap) {
            $assetId = $gap->meta['asset']['id'] ?? null;

            if (is_numeric($assetId)) {
                $stock[] = (int) $assetId;
            }
        }

        $badges = [];

        foreach ($stock === [] ? [] : StockView::badges($stock, $user) as $badge) {
            $badges[(string) $badge['id']] = $badge;
        }

        return [
            'count' => $report->count(),
            'suggestions' => $report->suggestions(),
            'mode' => Plugin::getInstance()->getSettings()->onPublish()->value,
            'gaps' => array_map(fn(Gap $gap) => $this->gap($gap, (int) $entry->id, $badges), $report->all()),
        ];
    }

    /**
     * A message in the editor's language: Ghostwriter's translation of the
     * key, else core's English.
     */
    public static function translate(Message $message): string
    {
        $params = array_map(fn($value) => (string) $value, $message->params);

        try {
            $text = Craft::t('ghostwriter', $message->key, $params);
        } catch (Throwable) {
            $text = $message->key;
        }

        return $text === $message->key ? $message->english() : $text;
    }

    /**
     * Where a gap is in the form: the element whose field it is (the entry,
     * or the Matrix entry or Neo block it sits in), the field's handle, and
     * the blocks above it, outermost first.
     *
     * @return array{elementId: int, handle: string, blocks: list<int>, field: string}
     */
    public static function location(Gap $gap, int $elementId): array
    {
        $owner = $elementId;
        $handle = $gap->path->handle();
        $blocks = [];

        foreach ($gap->path->segments as $segment) {
            if (is_string($segment)) {
                $handle = $segment;

                continue;
            }

            // A table's row has no element of its own: the table is the field.
            if (!$segment instanceof BlockRef || !is_numeric($segment->id)) {
                break;
            }

            $owner = (int) $segment->id;
            $blocks[] = $owner;
        }

        return ['elementId' => $owner, 'handle' => $handle, 'blocks' => $blocks, 'field' => $gap->path->handle()];
    }

    /**
     * A gap as a job carries it: enough for core to know what kind of gap
     * a request is for (and refuse a fact).
     *
     * @param array<string, mixed> $data
     */
    public static function gapFromArray(array $data): Gap
    {
        return Gap::make(
            GapKind::from((string) ($data['kind'] ?? GapKind::Expected->value)),
            FieldPath::parse((string) ($data['path'] ?? 'field')),
            (string) ($data['label'] ?? ''),
            isset($data['hint']) ? (string) $data['hint'] : null,
            isset($data['excerpt']) ? (string) $data['excerpt'] : null,
            (int) ($data['occurrence'] ?? 0),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $badges
     * @return array<string, mixed>
     */
    private function gap(Gap $gap, int $elementId, array $badges): array
    {
        $data = $gap->toArray();
        $data['message'] = self::translate($gap->message());

        $reason = is_string($gap->meta['reason'] ?? null) ? $gap->meta['reason'] : null;

        if ($reason !== null) {
            $data['reason'] = self::translate(new Message("gaps.guide.reason.{$reason}"));
        }

        $data['speech'] = self::translate(new Message('gaps.speech.' . $gap->kind->value));
        $data['blocks'] = $gap->blocks();
        $data['fixes'] = array_map(fn(Fix $fix) => ['label' => self::translate($fix->label)] + $fix->toArray(), $gap->fixes);
        $data['location'] = self::location($gap, $elementId);

        if (is_string($gap->meta['stockId'] ?? null)) {
            $data['stock'] = $badges[$gap->meta['stockId']] ?? null;
        }

        return $data;
    }

    /**
     * How often the section's live entries of this type fill each place,
     * as a pattern holding only that.
     */
    private function rates(Entry $entry, Schema $schema): ?Pattern
    {
        $section = $entry->getSection();

        if ($section === null) {
            return null;
        }

        $type = $entry->getType()->handle;
        $key = "ghostwriter:gap-rates:{$section->handle}:{$type}";

        try {
            $filled = Craft::$app->getCache()->getOrSet($key, fn() => Plugin::getInstance()->layouts->pattern($section->handle, $schema, $type)->filled, self::RATES_TTL);
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't learn which fields {$section->handle} entries fill: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }

        return is_array($filled) ? new Pattern(filled: $filled) : null;
    }
}
