<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Link;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Extras\ExtraSources;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detectors\UnlicensedStock;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Fix;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FixAction;
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
use NineteenNinetyFour\Ghostwriter\Core\Layout\FillRates;
use NineteenNinetyFour\Ghostwriter\Core\Layout\Pattern;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use nineteenninetyfour\ghostwriter\layouts\EntryReader;
use nineteenninetyfour\ghostwriter\layouts\Layouts;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\seo\HeadingProfiles;
use nineteenninetyfour\ghostwriter\stock\StockView;
use nineteenninetyfour\ghostwriter\suggest\CraftSeoFields;
use Throwable;
use yii\caching\TagDependency;

/**
 * "Finish this page" for Craft: core's GapFinder and PublishReadiness over
 * an entry as it stands (a draft, a provisional draft, or the live entry
 * being saved), with Craft's dialects and ports.
 *
 * - In sections Ghostwriter writes for, every detector runs. Elsewhere only
 *   stock previews are looked for, as the stock feature always did: a
 *   `[[name]]` in a site's own content is not ours to block.
 * - Nothing here calls a model. The section's fill rates (which empty
 *   fields are "expected", which images look needed) are counted over its
 *   newest live entries of the type (core's FillRates), kept per section
 *   and type, and counted again when an entry in the section is saved
 *   (forgetRates()); the publish guard doesn't need them, so it never
 *   reads them.
 */
class Gaps extends Component
{
    /** How long a section's fill rates are kept at most; a save in the section forgets them sooner. */
    private const RATES_TTL = 86400;

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
     * What is unfinished in the entry, for the menu's count and the guide.
     */
    public function report(Entry $entry): GapReport
    {
        return self::tidyReport($this->finder(self::writesHere($entry))->find($this->context($entry, rates: true)));
    }

    /**
     * Whether the entry may go live, for the publish guard: one finder run
     * for stock previews and every gap that blocks.
     */
    public function readiness(Entry $entry): Readiness
    {
        $mode = Plugin::getInstance()->getSettings()->onPublish();

        $readiness = (new PublishReadiness($this->finder(self::writesHere($entry)), $mode))->check($this->context($entry));

        return new Readiness(array_map(fn(Gap $gap) => self::tidy($gap), $readiness->problems()), $readiness->mode, self::tidyReport($readiness->report()));
    }

    /**
     * A gap named once for where it is. Core names a field in a block
     * "Block: Field", which reads twice when they share a name ("Text:
     * Text"): it becomes "the Text block", or "Subheading (in the Hero
     * block)" when they differ. Top-level fields keep their name.
     */
    public static function tidy(Gap $gap): Gap
    {
        $inBlock = false;

        foreach ($gap->path->segments as $segment) {
            if ($segment instanceof BlockRef && $segment->type !== '') {
                $inBlock = true;
            }
        }

        $parts = explode(': ', $gap->label);

        if (!$inBlock || count($parts) < 2) {
            return $gap;
        }

        $field = (string) array_pop($parts);
        $block = (string) array_pop($parts);
        $label = mb_strtolower($field) === mb_strtolower($block)
            ? Craft::t('ghostwriter', 'the {block} block', ['block' => $block])
            : Craft::t('ghostwriter', '{field} (in the {block} block)', ['field' => $field, 'block' => $block]);

        return new Gap($gap->id, $gap->kind, $gap->severity, $gap->path, $label, $gap->hint, $gap->excerpt, $gap->occurrence, $gap->fixes, $gap->meta);
    }

    public static function tidyReport(GapReport $report): GapReport
    {
        return new GapReport(array_map(fn(Gap $gap) => self::tidy($gap), $report->all()));
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
            entry: new EntryData((new EntryReader())->read($entry, $specs), (int) $entry->id, (string) $entry->title, group: $entry->getSection()?->handle, site: $entry->getSite()->handle),
            richText: new HtmlDialect(),
            links: self::links(),
            placeholders: new CraftPlaceholderAssets(),
            assets: new CraftAssetRefs(),
            targets: new CraftLinkTargets((int) $entry->siteId),
            stock: $plugin->stockUsages->ledgerIsEmpty() ? null : $plugin->domain->stock(),
            pattern: $rates ? $this->rates($entry, $schema) : null,
            session: $this->sessionGaps($entry),
            // The SEO fields (SEOmatic's, plain ones), so "Add a description
            // for search" is offered: a suggestion, never counted.
            seo: new CraftSeoFields(),
            group: self::group($entry),
            profile: self::profile($entry),
            // What a count to check was counted from: the person's
            // messages and answers, and the draft. A count whose list has
            // changed since says so.
            sources: $this->sources($entry),
        );
    }

    /**
     * The texts the counts in this entry may have been counted from, from
     * the piece that wrote it; none when Ghostwriter didn't.
     *
     * @return list<string>
     */
    public function sources(Entry $entry): array
    {
        $session = $this->session($entry);

        return $session === null ? [] : ExtraSources::fromSession($session)->all();
    }

    public static function links(): CraftLinks
    {
        return new CraftLinks(hyper: [Layouts::HYPER], link: [Link::class]);
    }

    /**
     * The gap list kept when Ghostwriter last put a draft into this entry,
     * and the links its SEO pass added ("Check 3 links Ghostwriter added").
     */
    public function sessionGaps(Entry $entry): SessionGaps
    {
        $id = (int) ($entry->getCanonicalId() ?? $entry->id);

        foreach (Plugin::getInstance()->sessions->forElement($id) as $session) {
            $gaps = SessionGaps::fromSession($session);

            if (!$gaps->isEmpty()) {
                return $gaps;
            }
        }

        return new SessionGaps();
    }

    /**
     * The piece that last put a draft into this entry (or is writing one
     * for it), the most recently changed first.
     */
    private function session(Entry $entry): ?Session
    {
        $id = (int) ($entry->getCanonicalId() ?? $entry->id);
        $sessions = Plugin::getInstance()->sessions->forElement($id);

        foreach ($sessions as $session) {
            if ($session->appliedAt !== null) {
                return $session;
            }
        }

        return $sessions[0] ?? null;
    }

    /**
     * Everything the guide needs, translated: the count for the menu, each
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
            // Gaps that bring the guide out on their own: what blocks, and images the page looks like it needs.
            'prompting' => count($report->prompting()),
            'suggestions' => $report->suggestions(),
            'mode' => Plugin::getInstance()->getSettings()->onPublish()->value,
            'gaps' => array_map(fn(Gap $gap) => $this->gap($gap, (int) $entry->id, $badges), $report->all()),
        ];
    }

    /**
     * A message in the editor's language: Ghostwriter's translation of the
     * key, else core's English; a sentence starts with a capital, a
     * fragment ("and 1 suggestion") is left as it is.
     */
    public static function translate(Message $message, bool $sentence = true): string
    {
        $params = array_map(fn($value) => (string) $value, $message->params);

        try {
            $text = Craft::t('ghostwriter', $message->key, $params);
        } catch (Throwable) {
            $text = $message->key;
        }

        $text = $text === $message->key ? $message->english() : $text;

        // A sentence that starts with a place: "The Text block is…".
        return $text === $message->key || !$sentence ? $text : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
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
        $location = self::locationOf($gap->path, $elementId);

        // An SEOmatic value is inside its field: the field is the place,
        // and `seomatic` the value in it (`seoDescription`).
        if (self::seomaticKey($gap->path) !== null) {
            $location['handle'] = $gap->path->handle();
            $location['seomatic'] = self::seomaticKey($gap->path);
        }

        return $location;
    }

    /** The SEOmatic value a path is at (`seoSettings.metaGlobalVars.seoDescription`: `seoDescription`); null for any other place. */
    public static function seomaticKey(FieldPath $path): ?string
    {
        $segments = $path->segments;

        return count($segments) === 3 && is_string($segments[0]) && $segments[1] === 'metaGlobalVars' && is_string($segments[2]) ? $segments[2] : null;
    }

    /**
     * The same for any place in an entry (a Suggest edits anchor's path).
     *
     * @return array{elementId: int, handle: string, blocks: list<int>, field: string}
     */
    public static function locationOf(FieldPath $path, int $elementId): array
    {
        $owner = $elementId;
        $handle = $path->handle();
        $blocks = [];

        foreach ($path->segments as $segment) {
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

        return ['elementId' => $owner, 'handle' => $handle, 'blocks' => $blocks, 'field' => $path->handle()];
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
        // The name in a label ("Link to {title}"), so the guide can cut just that short.
        // "Use a shorter one" has no writer here yet: an SEO value too long is the editor's to shorten.
        $fixes = array_values(array_filter($gap->fixes, fn(Fix $fix) => $fix->action !== FixAction::Shorten));
        $data['fixes'] = array_map(fn(Fix $fix) => ['label' => self::translate($fix->label), 'name' => isset($fix->label->params['title']) ? (string) $fix->label->params['title'] : null] + $fix->toArray(), $fixes);

        if ($fixes !== [] && !array_filter($data['fixes'], fn(array $fix) => !empty($fix['primary']))) {
            $data['fixes'][0]['primary'] = true;
        }
        $data['location'] = self::location($gap, $elementId);

        if (is_string($gap->meta['stockId'] ?? null)) {
            $data['stock'] = $badges[$gap->meta['stockId']] ?? null;
        }

        return $data;
    }

    /**
     * How often the section's newest live entries of this type fill each
     * place, and how many there were: FillRates over at most 20 of them,
     * kept until an entry in the section is saved.
     */
    private function rates(Entry $entry, Schema $schema): ?Pattern
    {
        $section = $entry->getSection();

        if ($section === null) {
            return null;
        }

        $type = $entry->getType()->handle;
        $key = "ghostwriter:gap-rates:{$section->handle}:{$type}:" . md5((string) json_encode($schema->toSpecs()));

        try {
            $rates = Craft::$app->getCache()->getOrSet($key, function() use ($section, $type, $schema): array {
                $specs = $schema->toSpecs();
                $reader = new EntryReader();
                $entries = array_map(
                    fn(Entry $sibling) => new EntryData($reader->read($sibling, $specs), (int) $sibling->id),
                    Layouts::published($section->handle, $type, FillRates::SIBLINGS),
                );
                $pattern = FillRates::pattern($schema, $entries);

                return ['entries' => $pattern->entries, 'filled' => $pattern->filled];
            }, self::RATES_TTL, new TagDependency(['tags' => [self::ratesTag($section->handle)]]));
        } catch (Throwable $exception) {
            Craft::warning("Ghostwriter couldn't count which fields {$section->handle} entries fill: {$exception->getMessage()}", 'ghostwriter');

            return null;
        }

        return is_array($rates) && is_array($rates['filled'] ?? null) ? new Pattern(entries: (int) ($rates['entries'] ?? 0), filled: $rates['filled']) : null;
    }

    /**
     * After an entry is saved: its section's fill rates are counted again
     * on the next check. Drafts and revisions change nothing published.
     */
    public static function forgetRates(Entry $entry): void
    {
        if ($entry->getIsDraft() || $entry->getIsRevision() || ($section = $entry->getSection()) === null) {
            return;
        }

        TagDependency::invalidate(Craft::$app->getCache(), self::ratesTag($section->handle));
    }

    private static function ratesTag(string $section): string
    {
        return "ghostwriter:gap-rates:{$section}";
    }

    /** What editors call the entry's section ("Journal"), for "Most Journal entries have one". */
    private static function group(Entry $entry): string
    {
        $section = $entry->getSection();

        return $section === null ? '' : Craft::t('site', $section->name);
    }

    /**
     * The section's render profile for this type, when a preview has shown
     * one: the block type the template prints the `h1` from is the hero.
     * Only what is stored; nothing is rendered or studied for it.
     */
    private static function profile(Entry $entry): ?RenderProfile
    {
        $section = $entry->getSection();

        if ($section === null) {
            return null;
        }

        try {
            $profile = HeadingProfiles::store()->for($section->handle, $entry->getType()->handle, $entry->getSite()->handle);
        } catch (Throwable) {
            return null;
        }

        return $profile !== null && $profile->rendered() ? $profile : null;
    }
}
