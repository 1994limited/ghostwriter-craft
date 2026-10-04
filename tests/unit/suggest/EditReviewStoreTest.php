<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\suggest;

use NineteenNinetyFour\Ghostwriter\Core\Suggest\EditReviewStore;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\EditReviewStoreContract;
use nineteenninetyfour\ghostwriter\suggest\DbEditReviewStore;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's EditReviewStoreContract against the plugin's table, with entries
 * as Craft names them: a section, an entry ID and a site ID.
 */
class EditReviewStoreTest extends TestCase
{
    use EditReviewStoreContract;

    protected function editReviewStore(): EditReviewStore
    {
        return $this->plugin->editReviewStore;
    }

    protected function reviewedEntry(string $id = 'services'): EntryRef
    {
        return new EntryRef('pages', abs(crc32($id)) % 100000 + 1, 1);
    }

    public function test_the_plugin_has_its_own(): void
    {
        $this->assertInstanceOf(DbEditReviewStore::class, $this->editReviewStore());
    }
}
