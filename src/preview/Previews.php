<?php

namespace nineteenninetyfour\ghostwriter\preview;

use NineteenNinetyFour\Ghostwriter\Core\Anchor\NormalisedText;
use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Plan;
use NineteenNinetyFour\Ghostwriter\Core\Arrange\Units;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Preview\BlockMap;
use NineteenNinetyFour\Ghostwriter\Core\Preview\MappedBlock;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Text\Draft;
use nineteenninetyfour\ghostwriter\drafts\DraftValues;
use nineteenninetyfour\ghostwriter\drafts\FieldValues;
use nineteenninetyfour\ghostwriter\layouts\DraftLayouts;
use nineteenninetyfour\ghostwriter\Plugin;
use yii\base\Component;

/**
 * The Preview tab: a session's draft rendered through the section's own
 * page template, with nothing written (approach A in the page preview
 * design, §7.3).
 *
 * prepare() works out the values exactly as "Use this draft" would
 * (DraftValues), marks a copy of them (core's PreviewMarkers), keeps that
 * copy in Craft's cache under a random key, and makes a Craft token that
 * routes the site request to `ghostwriter/preview/render`. element()
 * rebuilds the entry from it, unsaved, for the render action to show.
 *
 * Nothing about the entry is saved: no draft is made, no element or
 * nested entry is written. The only writes are the token row and the
 * cache entries, and both expire.
 */
class Previews extends Component
{
    /** How long a token, and the values it renders, last. */
    public const TTL = 900;

    /** How long a render is reused for the same values (§12). */
    public const REUSE = 600;

    /** A reused URL needs at least this long left. */
    private const MARGIN = 60;

    public const ROUTE = 'ghostwriter/preview/render';

    private const CACHE = 'ghostwriter:preview:';

    /**
     * @param array<int, array{type: string, title?: string, fields: array<string, mixed>, at?: string}> $nested
     *     CKEditor nested entries the draft adds, which don't exist yet, by a
     *     preview-only negative ID. Each is in its rich text value already
     *     (`<craft-entry data-entry-id="-101">`), or is put at the end of the
     *     value `at` names ("body", "pageBuilder/1/text").
     * @return array{url: string, map: array<int, array<string, mixed>>, expires: string, hash: string, plan: string, reused: bool, ms: int}
     */
    public function prepare(Session $session, ContentType $type, Entry $target, User $user, array $nested = [], ?string $plan = null): array
    {
        $started = microtime(true);
        $base = $this->baseFor($target, $user);
        $this->assertPreviewable($base);

        // The chosen layout, or the one a card shows (§5).
        $layouts = new DraftLayouts();
        $shown = $layouts->plan($session, $plan);
        $values = (new DraftValues())->for($session, $type, $base, readOnly: true, plan: $shown?->id);
        $hasTitle = (bool) $base->getType()->hasTitleField;
        $title = $hasTitle ? $values['title'] : (string) $base->title;
        $data = $hasTitle ? ['title' => $title] + $values['data'] : $values['data'];
        $data = $this->placeNested($data, $nested);

        // Units name the draft's own places. Another layout's are named by
        // the draft's own ids again, by their words, so comments find their
        // words in any layout (a piece of a unit, or an extra, has none).
        $units = Units::fromDraft(Draft::parse((string) $session->draft), $values['model']);
        $units = $session->units !== [] ? $units->restore($session->units) : $units;

        if ($shown !== null && $shown->id !== Plan::WRITER) {
            $units = self::sessionUnits($units, Units::fromDraft($layouts->draftData($session, $values['model'], $shown->id), $values['model']));
        }

        $marked = (new PreviewMarkers(fn(mixed $reference) => $this->assetName($reference)))->mark($data, $values['model'], $units);
        $withNested = $this->withNested($this->withImageFields($marked->map, $values['model'], $data), $nested, $marked->data);
        $map = $this->withComps($withNested['map']);

        $hash = sha1(json_encode([$marked->hash, $nested, (int) $base->id, (int) $base->siteId, (int) $user->id]) ?: '');
        $cache = Craft::$app->getCache();
        $reused = $cache->get(self::CACHE . 'h:' . $hash);

        if (is_array($reused) && ($reused['expires'] ?? 0) - time() > self::MARGIN) {
            return ['url' => $reused['url'], 'map' => $map->toArray(), 'expires' => gmdate(DATE_ATOM, $reused['expires']), 'hash' => $marked->hash, 'plan' => $shown?->id ?? Plan::WRITER, 'reused' => true, 'ms' => $this->since($started)];
        }

        // The address it will have: a new entry's temporary slug is made
        // from the draft's title, in memory, as saving it would.
        $clone = clone $base;
        $clone->title = $title;

        if (!$clone->slug || ElementHelper::isTempSlug($clone->slug)) {
            $clone->slug = ElementHelper::generateSlug($title, null, $base->getSite()->language);
        }

        Craft::$app->getElements()->setElementUri($clone);

        if ($clone->uri === null || $clone->uri === '') {
            throw new CannotPreview(CannotPreview::NO_URLS, Craft::t('ghostwriter', 'Pages in this section have no address, so there is no page to show. Blocks shows the draft.'));
        }

        $key = StringHelper::randomString(32);
        $expires = time() + self::TTL;

        $cache->set(self::CACHE . 'v:' . $key, [
            'id' => (int) $base->id,
            'siteId' => (int) $base->siteId,
            'title' => $hasTitle ? $marked->data['title'] : null,
            'slug' => $clone->slug,
            'values' => (new FieldValues())->forCraft($marked->data, $values['schema'], $values['existing']),
            'nested' => $withNested['entries'],
        ], self::TTL);

        // No usage limit: Craft adds the token to every link on the page,
        // which the panel cancels, and a limit would stop the same URL
        // being loaded again (a reopened panel, a thumbnail).
        $token = Craft::$app->getTokens()->createToken([self::ROUTE, ['key' => $key]], null, (new DateTime())->setTimestamp($expires));

        if ($token === false) {
            throw new \RuntimeException('The preview token could not be made.');
        }

        $general = Craft::$app->getConfig()->getGeneral();
        $url = UrlHelper::siteUrl($clone->uri === Element::HOMEPAGE_URI ? '' : $clone->uri, [
            $general->tokenParam => $token,
            'x-craft-preview' => Craft::$app->getSecurity()->hashData(StringHelper::randomString(10)),
        ], null, (int) $base->siteId);

        $cache->set(self::CACHE . 'h:' . $hash, ['url' => $url, 'expires' => $expires], self::REUSE);

        return ['url' => $url, 'map' => $map->toArray(), 'expires' => gmdate(DATE_ATOM, $expires), 'hash' => $marked->hash, 'plan' => $shown?->id ?? Plan::WRITER, 'reused' => false, 'ms' => $this->since($started)];
    }

    /**
     * Whether the section can show this entry as a page on its site: it has
     * URLs and a template there.
     */
    public function assertPreviewable(Entry $entry): void
    {
        $settings = $entry->getSection()?->getSiteSettings()[$entry->siteId] ?? null;

        if (!$settings || !$settings->hasUrls) {
            throw new CannotPreview(CannotPreview::NO_URLS, Craft::t('ghostwriter', 'Pages in this section have no address, so there is no page to show. Blocks shows the draft.'));
        }

        if (!$settings->template) {
            throw new CannotPreview(CannotPreview::NO_TEMPLATE, Craft::t('ghostwriter', 'This section has no page template, so there is no page to show. Blocks shows the draft.'));
        }
    }

    /**
     * What prepare() stored under a key, or null once it has expired.
     *
     * @return array<string, mixed>|null
     */
    public function stored(string $key): ?array
    {
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $key)) {
            return null;
        }

        $payload = Craft::$app->getCache()->get(self::CACHE . 'v:' . $key);

        return is_array($payload) ? $payload : null;
    }

    /**
     * The entry as the draft would make it, unsaved: the form's own draft
     * (or the entry) with the draft's values set in memory, its address
     * worked out, and the CKEditor nested entries it adds handed over in
     * memory too. Never saved.
     *
     * @param array<string, mixed> $payload What prepare() stored.
     */
    public function element(array $payload): ?Entry
    {
        /** @var Entry|null $element */
        $element = Entry::find()
            ->id((int) $payload['id'])
            ->siteId((int) $payload['siteId'])
            ->drafts(null)
            ->provisionalDrafts(null)
            ->status(null)
            ->one();

        if (!$element) {
            return null;
        }

        if ($payload['title'] !== null) {
            $element->title = (string) $payload['title'];
        }

        $element->slug = (string) $payload['slug'];
        $element->setFieldValues((array) $payload['values']);
        Craft::$app->getElements()->setElementUri($element);

        if ($payload['nested'] ?? []) {
            (new NestedEntries())->swapIn($element, (array) $payload['nested']);
        }

        // As Craft's own preview: a draft takes its place in the structure
        // from the entry it is a draft of.
        if (!$element->lft && $element->getIsDerivative()) {
            $canonical = $element->getCanonical(true);
            $element->structureId = $canonical->structureId;
            $element->root = $canonical->root;
            $element->lft = $canonical->lft;
            $element->rgt = $canonical->rgt;
            $element->level = $canonical->level;
        }

        $element->previewing = true;

        return $element;
    }

    /**
     * The response headers of a preview render (§13): third-party scripts
     * and their beacons blocked, no form posts, framed only by the control
     * panel, never cached, indexed or referred.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $hosts = array_filter(array_map('trim', Plugin::getInstance()->getSettings()->previewScriptHosts), fn(string $host) => preg_match('/^[a-z0-9*.:\/-]+$/i', $host) === 1);

        return [
            'Content-Security-Policy' => trim("script-src 'self' 'unsafe-inline' 'unsafe-eval' " . implode(' ', $hosts)) . "; connect-src 'self'; form-action 'none'; frame-ancestors 'self'; base-uri 'self'",
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Ghostwriter-Preview' => '1',
        ];
    }

    /**
     * The entry to render from: the form's draft when it is one, the
     * person's own provisional draft of the entry when they have one, or
     * the entry itself. Never a new draft (Applier makes one; this mustn't).
     */
    public function baseFor(Entry $target, User $user): Entry
    {
        if ($target->getIsDraft()) {
            return $target;
        }

        /** @var Entry|null $provisional */
        $provisional = Entry::find()
            ->draftOf($target)
            ->provisionalDrafts()
            ->draftCreator($user)
            ->siteId($target->siteId)
            ->status(null)
            ->one();

        return $provisional ?? $target;
    }

    /**
     * The file name of a stored asset reference (an ID), for the locator's
     * image fallback.
     */
    private function assetName(mixed $reference): ?string
    {
        if (!is_numeric($reference)) {
            return null;
        }

        static $names = [];

        return $names[(int) $reference] ??= Asset::find()->id((int) $reference)->status(null)->site('*')->unique()->one()?->filename;
    }

    /**
     * Top-level image fields (a journal's hero image) as blocks of their
     * own, found by file name: core maps only fields that hold writing.
     *
     * @param array<string, mixed> $data
     */
    /**
     * Another layout's units, with the ids of the draft's units that have
     * the same words (where exactly one has them); the rest get ids no
     * comment can hold.
     */
    public static function sessionUnits(Units $ours, Units $theirs): Units
    {
        $byText = [];

        foreach ($ours->all() as $unit) {
            if (trim($unit->markdown) !== '') {
                $byText[NormalisedText::string($unit->markdown)][] = $unit->id;
            }
        }

        $named = [];
        $taken = [];
        $other = 0;

        foreach ($theirs->all() as $unit) {
            $ids = $byText[NormalisedText::string($unit->markdown)] ?? [];
            $id = count($ids) === 1 && !isset($taken[$ids[0]]) ? $ids[0] : 'z' . ++$other;
            $taken[$id] = true;
            $named[] = $unit->withId($id);
        }

        return Units::of($named);
    }

    private function withImageFields(BlockMap $map, Schema $schema, array $data): BlockMap
    {
        $byHandle = [];
        $next = 0;

        foreach ($map->blocks as $block) {
            $byHandle[explode('/', $block->path)[0]][] = $block;

            if (preg_match('/^f(\d+)$/', $block->key, $m)) {
                $next = max($next, (int) $m[1]);
            }
        }

        $blocks = [];

        foreach ($schema->fields as $field) {
            if ($field->files && !empty($data[$field->handle])) {
                $names = array_values(array_filter(array_map(fn($reference) => $this->assetName($reference), (array) $data[$field->handle])));

                if ($names !== []) {
                    $blocks[] = new MappedBlock('f' . ++$next, MappedBlock::FIELD, $field->handle, $field->label !== '' ? $field->label : $field->handle, assets: $names, type: $field->handle);
                }
            }

            array_push($blocks, ...($byHandle[$field->handle] ?? []));
            unset($byHandle[$field->handle]);
        }

        foreach ($byHandle as $rest) {
            array_push($blocks, ...$rest);
        }

        return new BlockMap($blocks);
    }

    /**
     * CKEditor nested entries the data adds, as child blocks of the field
     * or block whose rich text holds them, keyed on after the last block
     * key, with markers at the end of each of their text values.
     *
     * @param array<int, array{type: string, title?: string, fields: array<string, mixed>}> $nested
     * @param array<string, mixed> $data The marked data.
     * @return array{map: BlockMap, entries: array<int, array{type: string, title: ?string, fields: array<string, mixed>}>}
     */
    private function withNested(BlockMap $map, array $nested, array $data): array
    {
        $next = 0;

        foreach ($map->keys() as $key) {
            if (preg_match('/^b(\d+)$/', $key, $m)) {
                $next = max($next, (int) $m[1]);
            }
        }

        $blocks = $map->blocks;
        $entries = [];

        foreach ($nested as $id => $spec) {
            $id = (int) $id;
            $entryType = Craft::$app->getEntries()->getEntryTypeByHandle((string) ($spec['type'] ?? ''));

            if ($id >= 0 || !$entryType) {
                continue;
            }

            $key = 'b' . ++$next;
            $owner = $this->ownerKey($data, $id);
            $parent = $owner !== null ? $map->get($owner) : null;
            $fields = [];
            $values = (array) ($spec['fields'] ?? []);

            foreach (array_values($entryType->getFieldLayout()->getCustomFields()) as $index => $field) {
                $value = $values[$field->handle] ?? null;

                if (is_string($value) && trim($value) !== '') {
                    $marker = PreviewMarkers::encode("{$key}.{$index}");
                    $values[$field->handle] = preg_match('/^\s*</', $value) ? PreviewMarkers::markHtml($value, [PreviewMarkers::LAST => $marker]) : PreviewMarkers::markText($value, $marker);
                    $fields[$index] = $field->handle;
                }
            }

            $entries[$id] = ['type' => $entryType->handle, 'title' => isset($spec['title']) ? (string) $spec['title'] : null, 'fields' => $values];
            $at = count($blocks);

            foreach ($blocks as $i => $block) {
                if ($block->key === $owner || ($owner !== null && $block->parent === $owner)) {
                    $at = $i + 1;
                }
            }

            array_splice($blocks, $at, 0, [new MappedBlock($key, MappedBlock::BLOCK, ($parent?->path ?? '') . '/~' . abs($id), Craft::t('site', $entryType->name), $owner, fields: $fields, type: $entryType->handle)]);
        }

        return ['map' => new BlockMap($blocks), 'entries' => $entries];
    }

    /**
     * Nested entries given a place (`at`) and not yet in their value, put
     * at its end, as CKEditor stores them.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $nested
     * @return array<string, mixed>
     */
    private function placeNested(array $data, array $nested): array
    {
        foreach ($nested as $id => $spec) {
            $at = array_values(array_filter(explode('/', (string) ($spec['at'] ?? '')), fn(string $part) => $part !== ''));
            $value = &$data;

            foreach ($at as $part) {
                if (!is_array($value) || !array_key_exists(is_numeric($part) ? (int) $part : $part, $value)) {
                    unset($value);
                    continue 2;
                }

                $value = &$value[is_numeric($part) ? (int) $part : $part];
            }

            if ($at !== [] && is_string($value) && !str_contains($value, 'data-entry-id="' . (int) $id . '"')) {
                $value .= '<craft-entry data-entry-id="' . (int) $id . '"></craft-entry>';
            }

            unset($value);
        }

        return $data;
    }

    /**
     * The key of the field or block whose value holds a nested entry: the
     * last marker in that value is always the field's own.
     *
     * @param array<string, mixed> $data
     */
    private function ownerKey(array $data, int $id): ?string
    {
        $owner = null;

        array_walk_recursive($data, function($value) use ($id, &$owner): void {
            if ($owner === null && is_string($value) && preg_match('/data-entry-id=["\']?' . preg_quote((string) $id, '/') . '["\'\s>]/', $value)) {
                $markers = PreviewMarkers::decode($value);
                $owner = $markers === [] ? null : end($markers)['key'];
            }
        });

        return $owner;
    }

    /**
     * A stock stand-in shows as its comp to editors (stock design §7.0),
     * at the comp's own address: the comp's ID is in that path, so it
     * goes beside the stand-in's file name for the locator to match.
     */
    private function withComps(BlockMap $map): BlockMap
    {
        $comps = Plugin::getInstance()->stockComps->compNames();

        if ($comps === []) {
            return $map;
        }

        $blocks = [];

        foreach ($map->blocks as $block) {
            $extra = array_values(array_filter(array_map(fn(string $name) => $comps[$name] ?? null, $block->assets)));
            $blocks[] = $extra === [] ? $block : new MappedBlock($block->key, $block->kind, $block->path, $block->label, $block->parent, $block->units, $block->fields, [...$block->assets, ...$extra], $block->anchors, $block->type);
        }

        return new BlockMap($blocks);
    }

    private function since(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
