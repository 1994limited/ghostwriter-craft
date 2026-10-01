<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use nineteenninetyfour\ghostwriter\migrations\m261002_000000_database_storage;
use craft\helpers\FileHelper;
use nineteenninetyfour\ghostwriter\migrations\FileImport;
use nineteenninetyfour\ghostwriter\sessions\Session;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use nineteenninetyfour\ghostwriter\types\ContentType;

/**
 * Everything is kept in the database: nothing is written to the project,
 * pieces of writing are their owner's alone, and what older versions kept
 * as files is brought in on upgrade.
 */
class StorageTest extends TestCase
{
    public function testNothingIsWrittenToTheProject(): void
    {
        $this->plugin->voiceGuide->save('# Voice');
        $this->plugin->imageryGuide->save('# Images');
        $this->plugin->types->save(ContentType::fromArray('guide', ['title' => 'Guide', 'section' => 'articles']));
        $this->plugin->ideas->add(['title' => 'An idea', 'section' => 'articles']);
        $this->plugin->voiceState->update(['status' => 'working']);
        $this->plugin->onboarding->hide();
        $this->plugin->sessions->save(Session::start('guide', [], 1));

        $this->assertSame('# Voice', trim($this->plugin->voiceGuide->get()));
        $this->assertSame('Guide', $this->plugin->types->find('guide')->title);
        $this->assertSame('working', $this->plugin->voiceState->get()['status']);
        $this->assertTrue($this->plugin->onboarding->hidden());
        $this->assertDirectoryDoesNotExist($this->workspace . '/guides');
        $this->assertDirectoryDoesNotExist($this->workspace . '/storage');
    }

    public function testAPieceOfWritingIsItsOwnersAlone(): void
    {
        $this->plugin->types->save(ContentType::fromArray('guide', ['title' => 'Guide', 'section' => 'articles']));

        $owner = $this->signIn();
        $session = $this->plugin->sessions->save(Session::start('guide', ['what' => 'Mine.'], $owner->id));
        $this->assertSame(200, $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['status']);

        $other = $this->signIn();

        $this->assertSame(404, $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['status']);
        $this->assertSame(404, $this->action('ghostwriter/sessions/delete', ['id' => $session->id])['status']);
        $this->assertSame([], $this->plugin->sessions->forUser($other->id));
        $this->assertSame([$session->id], array_map(fn(Session $s) => $s->id, $this->plugin->sessions->forUser($owner->id)));
        $this->assertNotNull($this->plugin->sessions->find($session->id));
    }

    public function testWhatOlderVersionsKeptAsFilesIsBroughtIn(): void
    {
        $guides = $this->workspace . '/guides';
        $storage = $this->workspace . '/storage';
        $owner = $this->signIn();

        FileHelper::writeToFile("{$guides}/voice.md", "# Our voice\n");
        FileHelper::writeToFile("{$guides}/types/case-study.yaml", "title: Case study\nsection: articles\n");
        FileHelper::writeToFile("{$guides}/ideas.yaml", "ideas:\n  - id: abc123\n    title: Planting under trees\n    section: articles\n    status: open\n");
        FileHelper::writeToFile("{$storage}/kinds.json", json_encode(['articles' => ['dismissed' => ['Press release']]]));
        FileHelper::writeToFile("{$storage}/sessions/" . str_repeat('a', 26) . '.json', json_encode(['id' => str_repeat('a', 26), 'type' => 'case-study', 'user_id' => $owner->id, 'answers' => [], 'messages' => [], 'updated_at' => '2026-09-30T10:00:00+00:00']));
        FileHelper::writeToFile("{$storage}/sessions/" . str_repeat('b', 26) . '.json', json_encode(['id' => str_repeat('b', 26), 'type' => 'case-study', 'user_id' => 999999]));

        // Something already in the database is kept.
        $this->plugin->imageryGuide->save('# Images already here');
        FileHelper::writeToFile("{$guides}/imagery.md", "# Older images\n");

        (new FileImport(new m261002_000000_database_storage()))->run();
        (new FileImport(new m261002_000000_database_storage()))->run();

        $this->assertSame('# Our voice', trim($this->plugin->voiceGuide->get()));
        $this->assertSame('# Images already here', trim($this->plugin->imageryGuide->get()));
        $this->assertSame('Case study', $this->plugin->types->find('case-study')->title);
        $this->assertSame(['abc123'], array_keys($this->plugin->ideas->all()));
        $this->assertSame(['Press release'], $this->plugin->kinds->get('articles')['dismissed']);
        $this->assertSame($owner->id, $this->plugin->sessions->find(str_repeat('a', 26))->userId);
        // A session whose person no longer exists comes in without one.
        $this->assertNull($this->plugin->sessions->find(str_repeat('b', 26))->userId);
        $this->assertCount(1, $this->plugin->store->documents('idea'));
    }

    public function testChangingStateFromTwoPlacesLosesNothing(): void
    {
        $this->plugin->voiceState->addMessage('user', 'One');
        $this->plugin->voiceState->update(['status' => 'working']);
        $this->plugin->voiceState->addMessage('assistant', 'Two');

        $state = $this->plugin->voiceState->get();

        $this->assertSame(['One', 'Two'], array_column($state['messages'], 'content'));
        $this->assertSame('working', $state['status']);
    }
}
