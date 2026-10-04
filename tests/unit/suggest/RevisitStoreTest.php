<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\RevisitStoreContract;
use nineteenninetyfour\ghostwriter\suggest\DbRevisitStore;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's RevisitStoreContract against the plugin's tables: the rows, and
 * the links table indexed on the target, keyed by site ID.
 */
class RevisitStoreTest extends TestCase
{
    use RevisitStoreContract;

    protected function revisitStore(): RevisitStore
    {
        return $this->plugin->revisitStore;
    }

    protected function contractSite(bool $other = false): int|string
    {
        return $other ? 2 : 1;
    }

    public function test_the_plugin_has_its_own(): void
    {
        $this->assertInstanceOf(DbRevisitStore::class, $this->revisitStore());
    }

    public function test_a_link_is_matched_by_the_entry_it_holds_however_it_is_written(): void
    {
        $store = $this->revisitStore();
        $row = fn(string $id, array $links) => new \NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow(new EntryRef('pages', $id, 1), ucfirst($id), null, null, [], 10, '2026-10-04T10:00:00+00:00', 'abc', [], $links);

        $store->put($row('services', ['{entry:41@1:url||https://northfold.test/show-garden}']));
        $store->put($row('about', ['{entry:41@1:url}']));
        $store->put($row('contact', ['{entry:410@1:url}', 'https://example.org/']));

        $keys = fn(array $refs) => array_map(fn(EntryRef $ref) => (string) $ref->id, $refs);

        $this->assertEqualsCanonicalizing(['services', 'about'], $keys($store->linkingTo('{entry:41}', 1)));
        $this->assertSame(['contact'], $keys($store->linkingTo('https://example.org/', 1)));
    }
}
