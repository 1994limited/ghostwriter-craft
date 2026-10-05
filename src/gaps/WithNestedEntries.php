<?php

namespace nineteenninetyfour\ghostwriter\gaps;

use Craft;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Detector;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\GapContext;
use nineteenninetyfour\ghostwriter\suggest\CkeditorEntries;

/**
 * A core detector that reads the entries nested in CKEditor fields too,
 * once Suggest links has run on the page (GapContext::$proposals): the
 * links it found may be in a nested entry's own rich text, so "Link it"
 * steps (ProposedLinks) and "Link to your other pages" (FewLinks) read
 * the page as Suggest links did (CkeditorEntries, as Suggest edits reads
 * it). Before that, and for every other detector, Finish this page reads
 * the CKEditor field as it is.
 */
final class WithNestedEntries implements Detector
{
    public function __construct(private readonly Detector $inner) {}

    public function kinds(): array
    {
        return $this->inner->kinds();
    }

    public function usesModel(): bool
    {
        return $this->inner->usesModel();
    }

    public function detect(GapContext $context): iterable
    {
        return $this->inner->detect($context->proposals === null ? $context : self::expand($context));
    }

    /** The context with each CKEditor field's nested entries read as blocks beside it (`{handle}~entries`), by their IDs in the form. */
    public static function expand(GapContext $context): GapContext
    {
        $site = is_string($context->entry->site) ? Craft::$app->getSites()->getSiteByHandle($context->entry->site, true) : null;

        if ($site === null) {
            return $context;
        }

        [$schema, $entry] = (new CkeditorEntries((int) $site->id))->expand($context->schema, $context->entry);

        if ($schema === $context->schema) {
            return $context;
        }

        return new GapContext(
            schema: $schema,
            entry: $entry,
            richText: $context->richText,
            links: $context->links,
            placeholders: $context->placeholders,
            assets: $context->assets,
            targets: $context->targets,
            stock: $context->stock,
            pattern: $context->pattern,
            session: $context->session,
            sources: $context->sources,
            alt: $context->alt,
            seo: $context->seo,
            group: $context->group,
            profile: $context->profile,
            hosts: $context->hosts,
            proposals: $context->proposals,
        );
    }
}
