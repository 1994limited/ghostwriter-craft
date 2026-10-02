<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use nineteenninetyfour\ghostwriter\migrations\m261002_000000_database_storage;
use craft\helpers\FileHelper;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\GuideState;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Planning\Idea;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\migrations\FileImport;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Everything is kept in the database: nothing is written to the project,
 * pieces of writing are their owner's alone, and what older versions kept
 * as files is brought in on upgrade.
 */
class StorageTest extends TestCase
{
    public function testNothingIsWrittenToTheProject(): void
    {
        $this->plugin->domain->saveGuide(Guide::VOICE, '# Voice');
        $this->plugin->domain->saveGuide(Guide::IMAGERY, '# Images');
        $this->plugin->types->save($this->plugin->types->make('guide', ['title' => 'Guide', 'section' => 'articles']));
        $this->plugin->domain->plan()->add(['title' => 'An idea', 'section' => 'articles']);
        $this->plugin->domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->begin());
        $this->plugin->onboarding->hide();
        $this->plugin->sessions->save(Session::start(Format::Craft, 'guide', [], 1));

        $this->assertSame('# Voice', trim($this->plugin->domain->guide(Guide::VOICE)->body));
        $this->assertSame('Guide', $this->plugin->types->find('guide')->title);
        $this->assertSame('working', $this->plugin->domain->guideState(Guide::VOICE)->status);
        $this->assertTrue($this->plugin->onboarding->hidden());
        $this->assertDirectoryDoesNotExist($this->workspace . '/guides');
        $this->assertDirectoryDoesNotExist($this->workspace . '/storage');
    }

    public function testWithSharingOffAPieceOfWritingIsItsOwnersAlone(): void
    {
        $this->plugin->getSettings()->sharedConversations = false;
        $this->plugin->types->save($this->plugin->types->make('guide', ['title' => 'Guide', 'section' => 'articles']));

        $owner = $this->signIn();
        $session = $this->plugin->sessions->save(Session::start(Format::Craft, 'guide', ['what' => 'Mine.'], $owner->id));
        $this->assertSame(200, $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['status']);

        $other = $this->signIn();

        $this->assertSame(404, $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['status']);
        $this->assertSame(404, $this->action('ghostwriter/sessions/delete', ['id' => $session->id])['status']);
        $this->assertSame([], $this->plugin->domain->sessions()->visible($this->plugin->domain->viewer($other)));
        $this->assertSame([$session->id], array_map(fn(Session $s) => $s->id, $this->plugin->domain->sessions()->visible($this->plugin->domain->viewer($owner))));
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
        $this->plugin->domain->saveGuide(Guide::IMAGERY, '# Images already here');
        FileHelper::writeToFile("{$guides}/imagery.md", "# Older images\n");

        (new FileImport(new m261002_000000_database_storage()))->run();
        (new FileImport(new m261002_000000_database_storage()))->run();

        $this->assertSame('# Our voice', trim($this->plugin->domain->guide(Guide::VOICE)->body));
        $this->assertSame('# Images already here', trim($this->plugin->domain->guide(Guide::IMAGERY)->body));
        $this->assertSame('Case study', $this->plugin->types->find('case-study')->title);
        $this->assertSame(['abc123'], array_map(fn(Idea $idea) => $idea->id, $this->plugin->plans->ideas()));
        $this->assertSame(['Press release'], $this->plugin->types->suggestions('articles')->dismissed);
        $this->assertSame($owner->id, $this->plugin->sessions->find(str_repeat('a', 26))->startedBy);
        // A session whose person no longer exists comes in without one.
        $this->assertNull($this->plugin->sessions->find(str_repeat('b', 26))->startedBy);
        $this->assertCount(1, $this->plugin->store->documents('idea'));
    }

    public function testWorkWhoseJobWasStoppedIsShownAsFailedAndCanBeTriedAgain(): void
    {
        $this->plugin->types->save($this->plugin->types->make('guide', ['title' => 'Guide', 'section' => 'articles']));
        $owner = $this->signIn();
        $session = Session::start(Format::Craft, 'guide', ['what' => 'Mine.'], $owner->id);
        $session->status = Session::WORKING;
        $this->plugin->sessions->save($session);
        $find = fn() => $this->plugin->domain->sessions()->find($session->id, $this->plugin->domain->viewer());

        // Still within what a job is allowed: working.
        $this->assertSame(Session::WORKING, $find()->status);

        // Long past it, with nothing to say so: the job was stopped.
        $long = date('c', time() - 3600);
        $data = json_decode((string) (new \craft\db\Query())->select('data')->from(\nineteenninetyfour\ghostwriter\Store::SESSIONS)->where(['id' => $session->id])->scalar(), true);
        \craft\helpers\Db::update(\nineteenninetyfour\ghostwriter\Store::SESSIONS, ['data' => json_encode(['updated_at' => $long] + $data)], ['id' => $session->id]);

        $stuck = $find();
        $this->assertSame(Session::FAILED, $stuck->status);
        $this->assertStringContainsString('stopped before it finished', $stuck->error);

        $this->fake->respond('writer', 'What is it for?');
        $this->assertSame(200, $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Try again.'])['status']);

        // The same for the guide screens.
        $this->plugin->domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->begin());
        $this->assertSame('working', $this->plugin->domain->guideState(Guide::VOICE)->status);
        \craft\helpers\Db::update(\nineteenninetyfour\ghostwriter\Store::STATE, ['dateUpdated' => \craft\helpers\Db::prepareDateForDb(new \DateTime('-1 hour'))], ['name' => 'voice'], updateTimestamp: false);
        $this->assertSame('failed', $this->plugin->domain->guideState(Guide::VOICE)->status);
    }

    public function testChangingStateFromTwoPlacesLosesNothing(): void
    {
        $domain = $this->plugin->domain;
        $domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->addMessage('user', 'One'));
        $domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->begin());
        $domain->changeGuideState(Guide::VOICE, fn(GuideState $state) => $state->addMessage('assistant', 'Two'));

        $state = $domain->guideState(Guide::VOICE);

        $this->assertSame(['One', 'Two'], array_column($state->messages, 'content'));
        $this->assertSame('working', $state->status);
    }
}
