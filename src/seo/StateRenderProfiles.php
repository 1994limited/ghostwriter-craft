<?php

namespace nineteenninetyfour\ghostwriter\seo;

use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfiles;
use nineteenninetyfour\ghostwriter\Store;

/**
 * How each entry type's template prints headings (core's Seo\RenderProfile),
 * read from the Preview tab's renders: kept in the plugin's state table
 * (`seo.profiles`), keyed by section, entry type and site.
 */
class StateRenderProfiles implements RenderProfiles
{
    public const STATE = 'seo.profiles';

    public function __construct(private Store $store)
    {
    }

    /** The key for a section's entry type on a site: `pages.page.default`. */
    public static function key(string $section, string $entryType, string $site): string
    {
        return "{$section}.{$entryType}.{$site}";
    }

    public function get(string $key): ?RenderProfile
    {
        $profile = $this->store->state(self::STATE)[$key] ?? null;

        return is_array($profile) ? RenderProfile::fromArray($profile) : null;
    }

    /**
     * The profile for a section's entry type: the site's own, else another
     * site's (templates are usually shared), else null.
     */
    public function for(string $section, string $entryType, ?string $site = null): ?RenderProfile
    {
        if ($site !== null && ($profile = $this->get(self::key($section, $entryType, $site))) !== null) {
            return $profile;
        }

        foreach ($this->store->state(self::STATE) as $key => $profile) {
            if (is_array($profile) && str_starts_with((string) $key, "{$section}.{$entryType}.")) {
                return RenderProfile::fromArray($profile);
            }
        }

        return null;
    }

    public function put(RenderProfile $profile): void
    {
        $all = $this->store->state(self::STATE);
        $all[$profile->key] = $profile->toArray();
        ksort($all);
        $this->store->putState(self::STATE, $all);
    }

    public function all(): array
    {
        return array_values(array_map(fn(array $profile) => RenderProfile::fromArray($profile), array_filter($this->store->state(self::STATE), 'is_array')));
    }
}
