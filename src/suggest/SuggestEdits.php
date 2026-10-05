<?php

namespace nineteenninetyfour\ghostwriter\suggest;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Ai\Image;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\QuoteFinder;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\Sentences;
use NineteenNinetyFour\Ghostwriter\Core\Anchor\TextQuote;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\BlockRef;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\FieldPath;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\AnchorScope;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckContext;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\CheckText;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReview;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewAccess;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviews;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Finding;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Findings;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\ReviewInput;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SiteDigest;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\Suggestion;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\SuggestionState;
use nineteenninetyfour\ghostwriter\gaps\Gaps;
use nineteenninetyfour\ghostwriter\images\ImagePicker;
use nineteenninetyfour\ghostwriter\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Suggest edits on a Craft entry's edit screen: a review (core's
 * EditReviews) started when someone asks and run as a queued job, and
 * what the guide shows of it. Everything is read from the entry as the
 * person's form has it (their provisional draft, if they have one), and
 * nothing here saves the entry: accepted changes go into the form in the
 * browser, which Craft autosaves into the provisional draft. The only
 * save is alt text, on the asset, after its own confirm (the controller).
 *
 * Every suggestion goes to the guide with its words translated and its
 * place in the form, as Finish this page's gaps do, so the browser builds
 * no sentence.
 */
class SuggestEdits extends Component
{
    /**
     * What the checks read of the entry as it stands: with the index, the
     * decisions that keep things quiet, and the weekly link check's
     * results for its links.
     */
    public function context(Entry $entry, ?DateTimeImmutable $now = null): CheckContext
    {
        $now ??= new DateTimeImmutable();
        $plugin = Plugin::getInstance();
        $ref = EntryChecks::ref($entry);

        return $plugin->revisit->checks()->context(
            $entry,
            $now,
            index: $plugin->entryIndex,
            quieted: $plugin->revisit->quieted($ref, $now),
            external: $plugin->revisitStore->get($ref)?->external ?? [],
        );
    }

    /**
     * The review call's input, from the entry as it stands, with the voice
     * guide and the kind as the writer gets them.
     */
    public function input(Entry $entry, ?DateTimeImmutable $now = null, ?string $replyLanguage = null): ReviewInput
    {
        $context = $this->context($entry, $now);
        $findings = Findings::standard()->find($context);
        $plugin = Plugin::getInstance();
        $type = $this->kind($entry);

        return new ReviewInput(
            $context,
            $plugin->studio->inputs()->writerContext($type, $this->voice(), 'Images are not part of this review.'),
            $findings,
            SiteDigest::build($context, $findings),
            $this->images($findings),
            $replyLanguage ?? Craft::$app->language,
        );
    }

    /** How many parts a review of the page as it stands is read in, for the confirm. */
    public function calls(Entry $entry): int
    {
        try {
            return $this->input($entry)->calls();
        } catch (Throwable) {
            return 1;
        }
    }

    public function reviews(): EditReviews
    {
        return Plugin::getInstance()->revisit->reviews();
    }

    public function access(): EditReviewAccess
    {
        return EditReviewAccess::from(Plugin::getInstance()->domain->options());
    }

    /**
     * What the guide shows: the latest review, re-checked against the
     * entry as the form has it (its stale suggestions marked), how many
     * candidates the free checks found, and how many calls a review will
     * make. Never a model call.
     *
     * @return array<string, mixed>
     */
    public function forGuide(Entry $entry, ?User $user = null): array
    {
        $plugin = Plugin::getInstance();
        $user ??= Craft::$app->getUser()->getIdentity();
        $viewer = $plugin->domain->viewer($user);
        $context = $this->context($entry);
        $ref = EntryChecks::ref($entry);
        $canEdit = $user !== null && Craft::$app->getElements()->canSave($entry, $user);
        $access = $this->access();
        $preview = $this->reviews()->preview($context, $ref);
        $latest = is_array($preview['review']) ? EditReview::fromArray($preview['review']) : null;

        if ($latest !== null && !$access->canSee($latest, $viewer, $canEdit)) {
            $latest = null;
        }

        $running = $latest !== null && $latest->status->isRunning();
        $stale = $running && $latest->createdAt !== null && new DateTimeImmutable($latest->createdAt) < $context->now->modify('-' . EditReviews::STALE_RUN . ' seconds');
        // Free findings are only candidates: nothing reaches the editor
        // until the review has judged it in its paragraph. So the guide
        // shows a finished review's suggestions, and otherwise just how
        // many candidates there are.
        $suggestions = [];

        if ($latest !== null && !$running) {
            foreach ($latest->all() as $suggestion) {
                $shown = $this->display($suggestion->toArray(), $context, (int) $entry->id, $latest, $user);

                if ($shown !== null) {
                    $suggestions[] = $shown;
                }
            }
        }

        return [
            'configured' => $plugin->studio->configured(),
            'calls' => $this->calls($entry),
            'candidates' => count($preview['findings']),
            'review' => $latest === null ? null : [
                'id' => $latest->id,
                'status' => $stale ? 'failed' : $latest->status->value,
                'error' => $stale ? Craft::t('ghostwriter', 'The review stopped before it finished.') : self::error($latest->error),
                'by' => $viewer->is($latest->startedBy) ? null : self::name($latest->startedBy),
                'mine' => $viewer->is($latest->startedBy),
                'ago' => $latest->finishedAt !== null ? Craft::$app->getFormatter()->asRelativeTime((new DateTimeImmutable($latest->finishedAt))->getTimestamp()) : null,
                'fresh' => $latest->contentHash === EditReviews::contentHash($context),
                'calls' => $latest->calls,
                'truncated' => $latest->truncated,
                'tokens' => (int) ($latest->usage['input'] ?? 0) + (int) ($latest->usage['output'] ?? 0),
                'canDecide' => $access->canDecide($latest, $viewer, $canEdit),
                'version' => $latest->version,
            ],
            'running' => $running && !$stale,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * One suggestion as the guide shows it, or null when it has expired.
     *
     * @param array<string, mixed> $array Suggestion::toArray()
     * @return array<string, mixed>|null
     */
    public function display(array $array, CheckContext $context, int $elementId, ?EditReview $review = null, ?User $user = null): ?array
    {
        $suggestion = Suggestion::fromArray($array);
        $anchor = $suggestion->anchor;

        if ($suggestion->state === SuggestionState::Expired) {
            return null;
        }

        $text = $context->textAt($anchor->path->toString());
        $field = $text?->visit->field;
        $finding = null;

        foreach ($review?->findings ?? [] as $stored) {
            if (($stored['id'] ?? null) === $suggestion->finding) {
                $finding = Finding::fromArray($stored);
            }
        }

        $meta = $finding?->meta ?? [];
        $reason = $suggestion->reason;
        $seo = $suggestion->category->value === 'seo' || str_starts_with((string) ($finding?->kind ?? ''), 'seo-') ? $this->seoOf($anchor->path, $context) : null;
        $decision = $review?->lastDecision($suggestion->id);
        $location = self::here(Gaps::locationOf($anchor->path, $elementId), $elementId);

        // An SEOmatic value is inside its field: the field is the place.
        if ($seo !== null && $seo['seomatic']) {
            $location['handle'] = $anchor->path->handle();
        }

        return [
            'id' => $suggestion->id,
            'category' => $suggestion->category->value,
            'label' => self::text(new Message($suggestion->category->label())),
            'speech' => self::text(new Message($suggestion->category->speech())),
            'wording' => $suggestion->category->isWording(),
            'state' => $suggestion->state->value,
            'scope' => $anchor->scope->value,
            'path' => $anchor->path->toString(),
            'dotted' => $anchor->path->dotted(),
            'location' => $location,
            'place' => self::place($anchor->label, $anchor->path),
            'fieldType' => $seo !== null ? 'seo' : self::fieldType($field?->type),
            'quote' => $anchor->quote?->toArray(),
            'occurrence' => $anchor->occurrence,
            'reason' => $reason->text !== '' ? $reason->text : ($reason->message !== null ? self::text($reason->message) : ''),
            'source' => self::text($reason->sourceLabel()),
            'free' => $suggestion->free,
            'finding' => $suggestion->finding,
            'replacement' => $suggestion->replacement,
            'alternatives' => $suggestion->alternatives,
            'versions' => $review?->versions[$suggestion->id] ?? [],
            'fact' => $suggestion->fact === null ? null : [
                'ask' => $suggestion->fact->ask !== '' ? $suggestion->fact->ask : self::text(new Message('suggest.fact.ask')),
                'template' => $suggestion->fact->template,
                'without' => $suggestion->fact->without,
                'answer' => $suggestion->fact->answer->value,
            ],
            'link' => $suggestion->link === null ? null : self::link($suggestion->link->toArray()),
            'asset' => $anchor->asset === null ? null : $this->asset($anchor->asset, $context, $user),
            'seo' => $seo,
            'free_label' => $suggestion->replacement === null && $suggestion->fact === null && $suggestion->link === null ? self::text(new Message($anchor->scope === AnchorScope::Asset ? 'suggest.free.alt' : 'suggest.free.rewrite')) : null,
            'decided' => $decision === null || $decision->by === null || $suggestion->state->isOpen() ? null : [
                'state' => $decision->state->value,
                'by' => Plugin::getInstance()->domain->viewer($user)->is($decision->by) ? null : self::name($decision->by),
            ],
            'phrase' => is_string($meta['phrase'] ?? null) ? $meta['phrase'] : null,
            'context' => $anchor->scope === AnchorScope::Range && $anchor->quote !== null && $text !== null ? self::around($text, $anchor->quote, $anchor->occurrence) : null,
            'meta' => array_intersect_key($meta, array_flip(['limit', 'length', 'title', 'url', 'share'])),
        ];
    }

    /**
     * A core message in the editor's language, as Finish this page's are
     * (Gaps::translate()), with a place in the form named once.
     */
    public static function text(Message $message, bool $sentence = true): string
    {
        $params = $message->params;

        foreach (['label', 'field', 'place'] as $name) {
            if (is_string($params[$name] ?? null)) {
                $params[$name] = self::place($params[$name]);
            }
        }

        return Gaps::translate(new Message($message->key, $params), $sentence);
    }

    /**
     * A field named once for where it is, as Finish this page names a
     * gap's: "Heading (in the Hero block)", or "the Text block" when the
     * block and the field share a name.
     */
    public static function place(string $label, ?FieldPath $path = null): string
    {
        $inBlock = $path === null;

        foreach ($path?->segments ?? [] as $segment) {
            if ($segment instanceof BlockRef) {
                $inBlock = true;
            }
        }

        $parts = explode(': ', $label);

        if (!$inBlock || count($parts) < 2) {
            return $label;
        }

        $field = (string) array_pop($parts);
        $block = (string) array_pop($parts);

        return mb_strtolower($field) === mb_strtolower($block)
            ? Craft::t('ghostwriter', 'the {block} block', ['block' => $block])
            : Craft::t('ghostwriter', '{field} (in the {block} block)', ['field' => $field, 'block' => $block]);
    }

    /**
     * The rest of the sentence (or sentences) a quote sits in, either side
     * of it, so the guide shows the change in its sentence and the editor
     * can judge the fit.
     *
     * @return array{before: string, after: string}|null
     */
    public static function around(CheckText $text, TextQuote $quote, int $occurrence = 0): ?array
    {
        $plain = $text->plain;
        $match = (new QuoteFinder())->find($quote, $plain, $occurrence);

        if ($match === null) {
            return null;
        }

        $block = $text->blockAt($match->offset);
        [$blockStart, $blockLength] = $block !== null ? $text->blocks[$block] : [0, mb_strlen($plain)];
        $line = mb_substr($plain, $blockStart, $blockLength);
        [$at, $size] = Sentences::covering($line, $match->offset - $blockStart, $match->length);
        $start = $blockStart + $at;
        $end = $start + $size;

        return [
            'before' => mb_substr($plain, $start, max(0, $match->offset - $start)),
            'after' => mb_substr($plain, $match->offset + $match->length, max(0, $end - $match->offset - $match->length)),
        ];
    }

    /**
     * A place found by the blocks the review read, in the element the form
     * is editing now. A provisional draft has its own copy of each Matrix
     * entry it changed (whose canonical ID is the entry's), so a review of
     * the entry finds its blocks in the draft, and a review of a draft in
     * the entry.
     *
     * @param array{elementId: int, handle: string, blocks: list<int>, field: string} $location
     * @return array{elementId: int, handle: string, blocks: list<int>, field: string}
     */
    private static function here(array $location, int $elementId): array
    {
        if ($location['blocks'] === []) {
            return $location;
        }

        $owner = $elementId;
        $blocks = [];

        foreach ($location['blocks'] as $id) {
            $canonical = (int) ((new \craft\db\Query())->select('canonicalId')->from('{{%elements}}')->where(['id' => $id])->scalar() ?: $id);
            $found = (new \craft\db\Query())
                ->select('e.id')
                ->from(['e' => '{{%elements}}'])
                ->innerJoin(['o' => '{{%elements_owners}}'], '[[o.elementId]] = [[e.id]]')
                ->where(['o.ownerId' => $owner])
                ->andWhere(['or', ['e.id' => [$id, $canonical]], ['e.canonicalId' => [$id, $canonical]]])
                // Not anyone's draft of the block (an entry opened in a
                // slideout has its own): the copy the form shows.
                ->andWhere(['e.dateDeleted' => null, 'e.draftId' => null, 'e.revisionId' => null])
                ->orderBy(['e.id' => SORT_DESC])
                ->scalar();
            $owner = $found ? (int) $found : $id;
            $blocks[] = $owner;
        }

        return ['elementId' => $owner] + ['blocks' => $blocks] + $location;
    }

    /** The kind of control a field is, as the guide writes to it. */
    private static function fieldType(?string $class): ?string
    {
        return match (true) {
            $class === null => null,
            in_array($class, ['craft\ckeditor\Field', 'craft\redactor\Field'], true) => 'richtext',
            $class === 'title' => 'title',
            $class === \craft\fields\Link::class => 'link',
            default => 'text',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function asset(\NineteenNinetyFour\Ghostwriter\Core\Domain\Stock\AssetRef $ref, CheckContext $context, ?User $user): array
    {
        $alt = new CraftAssetAlt();
        $asset = $alt->asset($ref);

        return [
            'id' => $asset?->id,
            'filename' => $asset?->filename ?? basename($ref->path),
            'alt' => $context->gaps->alt?->altFor($ref),
            'uses' => $asset ? $alt->uses($asset) : 0,
            'canEdit' => $asset !== null && $user !== null && Craft::$app->getElements()->canSave($asset, $user),
            'url' => $asset ? Craft::$app->getAssets()->getThumbUrl($asset, 144, 144) : null,
        ];
    }

    /**
     * A link as the guide puts it in: the stored target (a reference tag
     * for an entry), the entry's ID and site for a Link field's picker,
     * and its title.
     *
     * @param array<string, mixed> $link
     * @return array<string, mixed>
     */
    private static function link(array $link): array
    {
        $target = is_scalar($link['target'] ?? null) ? (string) $link['target'] : '';
        $entry = preg_match('/^\{entry:(\d+)(?:@(\d+))?/', $target, $match) === 1 ? ['id' => (int) $match[1], 'siteId' => isset($match[2]) ? (int) $match[2] : null] : null;

        return $link + ['value' => $target, 'entry' => $entry];
    }

    /**
     * Where an SEO value is, for writing it.
     *
     * @return array<string, mixed>|null
     */
    private function seoOf(FieldPath $path, CheckContext $context): ?array
    {
        foreach ($context->gaps->seo?->in($context->gaps->schema, $context->gaps->entry) ?? [] as $field) {
            if ($field->path->toString() === $path->toString()) {
                return [
                    'seomatic' => count($path->segments) > 1,
                    'limit' => $field->limit,
                    'length' => $field->length(),
                    'text' => $field->text,
                    'inheritsFrom' => $field->inheritsFrom,
                    'source' => $field->source->value,
                    'writable' => $field->writable,
                ];
            }
        }

        return null;
    }

    /**
     * Thumbnails of images with no alt text, for the review call to
     * describe: at most four, never one whose licence forbids it (a
     * refused image's step stays "Describe it yourself").
     *
     * @param list<Finding> $findings
     * @return array<string, Image>
     */
    private function images(array $findings): array
    {
        $images = [];
        $alt = new CraftAssetAlt();
        $sampler = new \nineteenninetyfour\ghostwriter\images\ImageSampler();

        foreach ($findings as $finding) {
            if (count($images) >= ReviewInput::IMAGES_PER_CALL || $finding->kind !== 'missing-alt' || $finding->anchor->asset === null) {
                continue;
            }

            try {
                $asset = $alt->asset($finding->anchor->asset);
                // Read through the stock guard: no Getty or iStock image goes to a model.
                $image = $asset instanceof Asset ? $sampler->small($asset) : null;

                if ($image !== null) {
                    $images[$finding->id] = $image;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $images;
    }

    /** The kind the entry is written as: its section's type for its entry type, else a generic one. Finish's Suggest links reads it too. */
    public function kind(Entry $entry): \NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType
    {
        $types = Plugin::getInstance()->types;
        $section = $entry->getSection();

        foreach ($types->forSection((string) $section?->handle) as $type) {
            if ($type->variant === null || $type->variant === $entry->getType()->handle) {
                return $type;
            }
        }

        return $types->generic($section);
    }

    /** The voice guide, '' when none is written. */
    public function voice(): string
    {
        try {
            return (string) Plugin::getInstance()->domain->guide(Guide::VOICE)->body;
        } catch (Throwable) {
            return '';
        }
    }

    private static function name(int|string|null $id): ?string
    {
        if ($id === null || !is_numeric($id)) {
            return null;
        }

        $user = Craft::$app->getUsers()->getUserById((int) $id);

        return $user ? (string) ($user->getFriendlyName() ?? $user->username) : null;
    }

    private static function error(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        // A provider's message, already in words.
        if ($error !== 'unreadable') {
            return $error;
        }

        // The code core sets, in core's words (here too for a core without them).
        $words = self::text(new Message('suggest.review.error.unreadable'), sentence: false);

        return $words !== 'suggest.review.error.unreadable' ? $words : Craft::t('ghostwriter', 'the answer came back in a shape I couldn’t read. Try again.');
    }
}
