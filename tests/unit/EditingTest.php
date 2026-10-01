<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Text\EntryMerger;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Editing an entry that already exists, in conversation: its content as it
 * stands is the draft, and only the writing changes when it goes back in.
 */
class EditingTest extends TestCase
{
    use Sites;

    private Entry $one;

    protected function _before(): void
    {
        parent::_before();

        $this->makeArticlesSection();
        $two = $this->makeArticle('Two', 'Another paragraph that is about something else entirely, at length.');
        $this->one = $this->makeArticle('One', 'A paragraph about one thing that says something specific enough to read twice.', ['relatedEntry' => [$two->id]]);
    }

    public function testAnEntryOpensWithItsContentAsTheDraft(): void
    {
        $this->signIn(admin: true);

        $response = $this->action('ghostwriter/sessions/edit', ['elementId' => $this->one->id, 'siteId' => $this->one->siteId]);
        $detail = $response['data'];

        $this->assertSame(200, $response['status'], json_encode($detail));
        $this->assertTrue($detail['editing']);
        $this->assertStringStartsWith('title: One', $detail['draft']);
        $this->assertStringContainsString('A paragraph about one thing', $detail['draft']);
        $this->assertStringContainsString('## The Problem', $detail['draft']);

        // The switched-off gallery is not part of the page, so not of the draft.
        $this->assertStringNotContainsString('Switched off', $detail['draft']);
        $this->assertSame('I have the entry as it stands. Tell me what to change.', end($detail['messages'])['content']);
        $this->assertSame('editing', (new Presenter())->summary($this->plugin->sessions->find($detail['id']))['stage']);
    }

    public function testChangesNotYetUsedAreKeptWhenTheEntryIsOpenedAgain(): void
    {
        $this->signIn(admin: true);
        $open = fn() => $this->action('ghostwriter/sessions/edit', ['elementId' => $this->one->id, 'siteId' => $this->one->siteId])['data'];

        $first = $open();
        $session = $this->plugin->sessions->find($first['id']);
        $session->draft = str_replace('title: One', 'title: One, revised', $session->draft);
        $this->plugin->sessions->save($session);

        // Same conversation, same unsaved changes.
        $again = $open();
        $this->assertSame($first['id'], $again['id']);
        $this->assertStringStartsWith('title: One, revised', $again['draft']);

        // Unless the person asks to start again from the entry.
        $fresh = $this->action('ghostwriter/sessions/edit', ['elementId' => $this->one->id, 'siteId' => $this->one->siteId, 'fresh' => 1])['data'];
        $this->assertStringNotContainsString('revised', $fresh['draft']);

        $session = $this->plugin->sessions->find($first['id']);
        $session->draft = str_replace('title: One', 'title: One, revised', $session->draft);
        $this->plugin->sessions->save($session);

        // Once they have gone into the entry, it starts from the entry again.
        $session = $this->plugin->sessions->find($first['id']);
        $session->appliedAt = $session::now();
        $this->plugin->sessions->save($session);

        $this->assertStringStartsWith('title: One', $open()['draft']);
        $this->assertStringNotContainsString('revised', $open()['draft']);
    }

    public function testOnlyTheWritingChangesAndTheLiveEntryIsUntouched(): void
    {
        $this->signIn(admin: true);
        $id = $this->action('ghostwriter/sessions/edit', ['elementId' => $this->one->id, 'siteId' => $this->one->siteId])['data']['id'];

        $session = $this->plugin->sessions->find($id);
        $session->draft = str_replace(
            ['A paragraph about one thing', 'title: One'],
            ['A sharper paragraph about one thing', 'title: One, sharper'],
            $session->draft,
        );
        $this->plugin->sessions->save($session);

        $response = $this->action('ghostwriter/sessions/apply', ['id' => $id, 'elementId' => $this->one->id, 'siteId' => $this->one->siteId]);

        $this->assertSame(200, $response['status'], json_encode($response['data']));

        // A provisional draft: it opens with the entry, so needs no address.
        $this->assertNull($response['data']['draftId']);

        $draft = Entry::find()->draftOf($this->one)->provisionalDrafts()->status(null)->one();

        $this->assertNotNull($draft);
        $this->assertSame('One, sharper', $draft->title);

        $blocks = $draft->getFieldValue('pageBuilder')->status(null)->all();
        $this->assertSame(['hero', 'longForm', 'cards', 'related', 'gallery'], array_map(fn($block) => $block->getType()->handle, $blocks));
        $this->assertStringContainsString('A sharper paragraph', (string) $blocks[1]->getFieldValue('content'));

        // What the writer never sees is as it was: settings, a link to
        // another entry, the switched-off block.
        $this->assertTrue((bool) $blocks[1]->getFieldValue('numbered'));
        $this->assertSame(3, (int) $blocks[3]->getFieldValue('limit'));
        $this->assertFalse((bool) $blocks[4]->enabled);
        $this->assertSame(Entry::find()->section('articles')->title('Two')->ids(), $draft->getFieldValue('relatedEntry')->status(null)->ids());

        // The live entry is unchanged until the person saves.
        $live = Entry::find()->id($this->one->id)->status(null)->one();
        $this->assertSame('One', $live->title);
        $this->assertStringNotContainsString('sharper', (string) $live->getFieldValue('pageBuilder')->status(null)->all()[1]->getFieldValue('content'));

        $this->assertSame('changed', (new Presenter())->summary($this->plugin->sessions->find($id))['stage']);
    }

    public function testSomeoneWhoMayNotSaveTheEntryCannotOpenIt(): void
    {
        $this->signIn();

        $this->assertSame(403, $this->action('ghostwriter/sessions/edit', ['elementId' => $this->one->id, 'siteId' => $this->one->siteId])['status']);
    }

    public function testBlocksAreMatchedByTypeInOrder(): void
    {
        $schema = [
            ['handle' => 'title', 'kind' => 'text'],
            ['handle' => 'image', 'kind' => 'reference'],
            ['handle' => 'body', 'kind' => 'blocks', 'engine' => 'matrix', 'sets' => [
                'text' => ['fields' => [['handle' => 'copy', 'kind' => 'richtext'], ['handle' => 'width', 'kind' => 'choice']]],
                'quote' => ['fields' => [['handle' => 'copy', 'kind' => 'text']]],
            ]],
        ];

        $original = ['title' => 'Old', 'image' => [7], 'body' => [
            ['id' => 11, 'type' => 'text', 'enabled' => true, 'copy' => 'First', 'width' => 'wide'],
            ['id' => 12, 'type' => 'quote', 'enabled' => true, 'copy' => 'Said'],
            ['id' => 13, 'type' => 'text', 'enabled' => true, 'copy' => 'Second', 'width' => 'narrow'],
            ['id' => 14, 'type' => 'quote', 'enabled' => false, 'copy' => 'Hidden'],
        ]];

        // The writer moved the quote to the end and added a third text block.
        $built = ['body' => [
            ['type' => 'text', 'enabled' => true, 'copy' => 'First, better'],
            ['type' => 'text', 'enabled' => true, 'copy' => 'Second, better'],
            ['type' => 'quote', 'enabled' => true, 'copy' => 'Said, better'],
            ['type' => 'text', 'enabled' => true, 'copy' => 'New'],
        ]];

        $merged = (new EntryMerger())->merge($built, $original, $schema);

        $this->assertSame([7], $merged['image']);
        $this->assertSame([11, 13, 12, null, 14], array_map(fn(array $block) => $block['id'] ?? null, $merged['body']));
        $this->assertSame(['wide', 'narrow'], [$merged['body'][0]['width'], $merged['body'][1]['width']]);
        $this->assertSame('Second, better', $merged['body'][1]['copy']);
        $this->assertFalse($merged['body'][4]['enabled']);
    }
}
