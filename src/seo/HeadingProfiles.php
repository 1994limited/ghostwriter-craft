<?php

namespace nineteenninetyfour\ghostwriter\seo;

use Craft;
use craft\models\EntryType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Schema\EntryData;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use nineteenninetyfour\ghostwriter\Plugin;

/**
 * How an entry type's template prints headings (core's Seo\RenderProfile),
 * the same for the writer's prompt, the SEO pass and the planner: the
 * profile the Preview tab's renders stored, else what the section's
 * published entries show, else the default (the title is the H1).
 */
class HeadingProfiles
{
    /** How long a resolved profile is reused, in seconds: within a request, not across a queue worker's jobs. */
    private const REUSE = 30;

    /** @var array<string, array{0: RenderProfile, 1: int}> Resolved lately, with when. */
    private static array $resolved = [];

    /**
     * @param array<int, EntryData>|null $entries The entries already read for the pattern, when there are.
     */
    public static function for(ContentType $type, EntryType $entryType, Schema $schema, ?array $entries = null, ?string $site = null): RenderProfile
    {
        $plugin = Plugin::getInstance();
        $site ??= Craft::$app->getSites()->getPrimarySite()->handle;
        $key = StateRenderProfiles::key($type->group, $entryType->handle, $site);

        if (isset(self::$resolved[$key]) && time() - self::$resolved[$key][1] < self::REUSE) {
            return self::$resolved[$key][0];
        }

        $stored = self::store()->for($type->group, $entryType->handle, $site);

        if ($stored === null || !$stored->rendered()) {
            $entries ??= $plugin->layouts->study($type->group, $schema, $entryType->handle, $type->where, $type->examples)['entries'];
        }

        $profile = RenderProfile::resolve($key, $stored, $schema, $entries ?? [], $plugin->layouts->core->richText, self::label($type->group));
        self::$resolved[$key] = [$profile, time()];

        return $profile;
    }

    public static function store(): StateRenderProfiles
    {
        return new StateRenderProfiles(Plugin::getInstance()->store);
    }

    /** What editors call the section, for the developer note. */
    public static function label(string $section): string
    {
        $found = Craft::$app->getEntries()->getSectionByHandle($section);

        return $found ? Craft::t('site', $found->name) : $section;
    }

    /** Forget what was resolved, after a render changed a profile. */
    public static function forget(): void
    {
        self::$resolved = [];
    }
}
