<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\AnalyseSection;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\Launcher;
use nineteenninetyfour\ghostwriter\sessions\Session;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use nineteenninetyfour\ghostwriter\types\ContentType;
use nineteenninetyfour\ghostwriter\types\TypeState;

/**
 * Learning a section, the questionnaire, the conversation, and handing the
 * draft to the entry it is for.
 */
class WritingTest extends TestCase
{
    use Sites;

    private const DRAFT = "title: What Does a Website Cost?\nsummary: Why quotes vary, and what moves the number.\npageBuilder:\n  - type: hero\n  - type: longForm\n    content: |\n      ## Why are the quotes so far apart?\n\n      Because they are for **different** websites.\n  - type: related";

    protected function _before(): void
    {
        parent::_before();

        $this->makeArticlesSection();
        $this->makeNewsSection();
        $this->makePressSection();

        foreach (['One', 'Two', 'Three'] as $i => $title) {
            $this->makeArticle($title, "A paragraph about {$title} that says something specific enough to be worth reading twice over.", postDate: '2026-01-0' . ($i + 1));
        }
    }

    public function testLearningASectionSavesAContentType(): void
    {
        $this->fake->respond('type-analyst', "<type>\ntitle: Project article\ndescription: A write-up of one project.\nquestions:\n  - handle: what\n    label: What was built?\n    type: textarea\n    required: true\n  - handle: avoid\n    label: What must not appear?\nguidance: |\n  Open on the reader.\nchecklist:\n  - Facts come from the brief.\n</type>");

        (new AnalyseSection(['section' => 'articles']))->execute(null);

        $type = $this->plugin->types->find('project-article');

        $this->assertSame('Project article', $type->title);
        $this->assertSame('articles', $type->section);
        $this->assertSame(['what', 'avoid'], array_column($type->questions, 'handle'));
        $this->assertSame(TypeState::IDLE, $this->plugin->typeState->get('articles')['status']);
        $this->assertFileExists($this->workspace . '/guides/types/project-article.yaml');

        // The analyst was shown the fields, the pattern and a real entry.
        $prompt = $this->fake->prompted('type-analyst')[0]->prompt;
        $this->assertStringContainsString('in this order: hero, longForm, cards, related', $prompt);
        $this->assertStringContainsString('A paragraph about Three', $prompt);
    }

    public function testAnUnreadableAnalysisFailsWithoutSavingAnything(): void
    {
        $this->fake->respond('type-analyst', 'Sorry, I cannot help with that.');

        (new AnalyseSection(['section' => 'articles']))->execute(null);

        $this->assertSame([], $this->plugin->types->forSection('articles'));
        $this->assertSame(TypeState::FAILED, $this->plugin->typeState->get('articles')['status']);
    }

    public function testAnAnswerThatCannotBeReadIsAskedForAgain(): void
    {
        // Fenced YAML is read as it stands; a type with no questions is not,
        // and the analyst is told so and asked once more.
        $this->fake->respond(
            'type-analyst',
            "<type>\n```yaml\ntitle: Contact page\ndescription: How to reach the studio.\nguidance: Short.\n```\n</type>",
            "<type>\n```yaml\ntitle: Contact page\ndescription: How to reach the studio.\nquestions:\n  - handle: offices\n    label: Which offices are listed?\nguidance: Short.\n```\n</type>",
        );

        (new AnalyseSection(['section' => 'articles', 'title' => 'Contact page']))->execute(null);

        $this->assertSame(['offices'], array_column($this->plugin->types->find('contact-page')->questions, 'handle'));
        $this->assertSame(TypeState::IDLE, $this->plugin->typeState->get('articles')['status']);

        $retry = $this->fake->prompted('type-analyst')[1];
        $this->assertSame('Your answer could not be read: it had no questions. Reply again with the whole type, as one YAML document inside a <type> block and nothing else.', $retry->prompt);
        $this->assertSame(['user', 'assistant'], array_map(fn($message) => $message->role, $retry->history));
    }

    public function testThePanelIsToldAboutItsSection(): void
    {
        $this->signIn();

        $info = $this->action('ghostwriter/sections/show', ['section' => 'articles'], 'GET')['data'];

        $this->assertSame('Articles', $info['section']['title']);
        $this->assertCount(1, $info['types']);
        $this->assertTrue($info['types'][0]['generic']);
        $this->assertSame([], $info['kinds']);
        $this->assertSame(['Three', 'Two', 'One'], array_column($info['entries'], 'title'));
        $this->assertFalse($info['hasVoice']);

        $response = $this->action('ghostwriter/sections/analyse', ['section' => 'articles', 'title' => 'Case study', 'examples' => [$this->entry('One')->id, $this->makeEntry($this->press, 'Elsewhere')->id]]);

        $this->assertSame(TypeState::WORKING, $response['data']['state']['status']);

        // Entries from another section cannot be the model.
        $job = $this->queued(AnalyseSection::class)[0];
        $this->assertSame('Case study', $job->title);
        $this->assertSame([$this->entry('One')->id], $job->examples);

        // Not twice at once.
        $this->assertSame(409, $this->action('ghostwriter/sections/analyse', ['section' => 'articles'])['status']);
    }

    public function testOnlyChosenSectionsAreOffered(): void
    {
        $this->plugin->getSettings()->sections = ['press'];
        $this->signIn();

        $this->assertSame(200, $this->action('ghostwriter/sections/show', ['section' => 'press'], 'GET')['status']);
        $this->assertSame(404, $this->action('ghostwriter/sections/show', ['section' => 'articles'], 'GET')['status']);
        $this->assertSame(['press'], array_map(fn($section) => $section->handle, $this->plugin->types->sections()));
    }

    public function testKindsOfEntryAreFoundFromHowEntriesAreBuilt(): void
    {
        $this->signIn();

        foreach (['Landing A', 'Landing B'] as $title) {
            $this->makeArticle($title, 'A landing page paragraph.', ['pageBuilder' => [
                'new1' => ['type' => 'hero', 'enabled' => true, 'fields' => ['heading' => $title]],
                'new2' => ['type' => 'gallery', 'enabled' => true, 'fields' => ['caption' => 'Our work']],
            ]]);
        }

        // Built like nothing else, so it is not a kind.
        $this->makeArticle('One-off', 'A paragraph.', ['pageBuilder' => ['new1' => ['type' => 'related', 'enabled' => true, 'fields' => []]]]);

        $kinds = $this->action('ghostwriter/sections/show', ['section' => 'articles'], 'GET')['data']['kinds'];

        $this->assertCount(2, $kinds);
        $this->assertSame([3, 2], array_column($kinds, 'count'));
        $this->assertSame(['hero', 'gallery'], $kinds[1]['blocks']);
        $this->assertEqualsCanonicalizing(['Landing A', 'Landing B'], $kinds[1]['titles']);
        $this->assertStringStartsWith('Like Landing', $kinds[1]['label']);

        // Every press item is built alike, and there is no builder at all.
        $this->makeEntry($this->press, 'A');
        $this->makeEntry($this->press, 'B');
        $this->assertSame([], $this->action('ghostwriter/sections/show', ['section' => 'press'], 'GET')['data']['kinds']);
    }

    public function testATitleAndNotesAreExpandedIntoABriefToCheck(): void
    {
        $this->signIn();
        $this->saveType();

        $this->fake->respond('brief-writer', "<brief>\nwhat: |\n  A search that narrows 400 products by what the customer needs.\n  [Add: the client and what changed after launch]\navoid: Client names.\nmade_up: ignored\n</brief>");

        $this->assertSame(422, $this->action('ghostwriter/sessions/brief', ['type' => 'project', 'notes' => 'No title.'])['status']);

        $response = $this->action('ghostwriter/sessions/brief', ['type' => 'project', 'title' => 'Faceted search', 'notes' => 'For a kitchen appliance maker.']);

        $this->assertSame(['answers' => [
            'what' => "A search that narrows 400 products by what the customer needs.\n[Add: the client and what changed after launch]",
            'avoid' => 'Client names.',
        ]], $response['data']);

        // It was given the title, the notes and what the section already has.
        $prompt = $this->fake->prompted('brief-writer')[0];
        $this->assertStringContainsString('Working title: Faceted search', $prompt->prompt);
        $this->assertStringContainsString('For a kitchen appliance maker.', $prompt->prompt);
        $this->assertStringContainsString('- Three', $prompt->instructions);

        // Nothing has been started.
        $this->assertSame([], $this->plugin->sessions->all());
    }

    public function testTheQuestionnaireRequiresItsRequiredAnswersAndStartsWriting(): void
    {
        $user = $this->signIn(admin: true);
        $this->saveType();
        $draft = $this->newDraft($this->articles);

        $refused = $this->action('ghostwriter/sessions/start', ['type' => 'project', 'answers' => ['avoid' => 'Client names.'], 'elementId' => $draft->id]);

        $this->assertSame(422, $refused['status']);
        $this->assertArrayHasKey('what', $refused['data']['errors']);
        $this->assertSame([], $this->queued(RunSessionTurn::class));

        $started = $this->action('ghostwriter/sessions/start', ['type' => 'project', 'answers' => ['what' => 'A faceted search.'], 'elementId' => $draft->id, 'examples' => [$this->entry('Two')->id, $this->makeEntry($this->press, 'Elsewhere')->id]]);

        $this->assertSame(Session::WORKING, $started['data']['status']);

        $session = $this->plugin->sessions->find($started['data']['id']);

        $this->assertSame($user->id, $session->userId);
        $this->assertSame($draft->id, $session->elementId);
        $this->assertSame([$this->entry('Two')->id], $session->examples);
        $this->assertStringContainsString('A faceted search.', $session->messages[0]['content']);
        $this->assertStringContainsString('(not answered)', $session->messages[0]['content']);
        $this->assertSame($session->id, $this->queued(RunSessionTurn::class)[0]->sessionId);
    }

    public function testTheWriterCanInterviewFirstAndDraftSecond(): void
    {
        $this->plugin->voiceGuide->save("# Tone of voice\n\nTwo punchlines at most.");
        $type = $this->saveType();
        $session = $this->startedSession();

        $this->fake->respond('writer', '<reply>1. Which projects can I cite?</reply>', "<reply>Here is the draft.</reply>\n<draft>\n" . self::DRAFT . "\n</draft>");

        $this->runTurn($session);

        $session = $this->plugin->sessions->find($session->id);
        $this->assertNull($session->draft);
        $this->assertSame('1. Which projects can I cite?', end($session->messages)['content']);

        // The panel is told the writer is waiting for an answer.
        $this->assertTrue((new Presenter())->detail($session)['waitingOnYou']);
        $this->assertSame('interview', (new Presenter())->summary($session)['stage']);

        $session->addMessage('user', 'The pub and the fitness app.');
        $this->plugin->sessions->save($session);

        $this->runTurn($session);

        $session = $this->plugin->sessions->find($session->id);
        $this->assertSame(self::DRAFT, $session->draft);
        $this->assertFalse((new Presenter())->detail($session)['waitingOnYou']);

        // The conversation records that this turn wrote the draft; the turn
        // that only asked a question recorded nothing.
        $this->assertSame('written', end($session->messages)['draft']['change']);
        $this->assertArrayNotHasKey('draft', $session->messages[1]);
        $this->assertSame('What Does a Website Cost?', $session->title());
        $this->assertSame(Session::IDLE, $session->status);

        // The writer's instructions carry the voice, the type's guidance, the
        // fields read from the layout, the pattern and a real example.
        $instructions = $this->plugin->studio->writerInstructions($type, $this->plugin->voiceGuide->get());

        foreach (['Two punchlines at most.', 'Open on the reader. Two sections.', '`longForm`: LongForm', 'in this order: hero, longForm, cards, related', '<example number="1">', 'A paragraph about Three'] as $expected) {
            $this->assertStringContainsString($expected, $instructions);
        }

        $this->assertStringContainsString('The pub and the fitness app.', $this->fake->prompted('writer')[1]->prompt);
    }

    public function testARevisionIsSentTheCurrentDraft(): void
    {
        $this->saveType();
        $session = $this->startedSession();
        $session->draft = self::DRAFT;
        $session->addMessage('assistant', 'Here is the draft.');
        $session->addMessage('user', 'Shorten the opening.');
        $this->plugin->sessions->save($session);

        $this->fake->respond('writer', '<reply>Shortened.</reply><draft>' . str_replace('Because they are for **different** websites.', 'Different websites.', self::DRAFT) . '</draft>');

        $this->runTurn($session);

        $this->assertStringContainsString('Different websites.', $this->plugin->sessions->find($session->id)->draft);

        $request = $this->fake->prompted('writer')[0];
        $this->assertStringContainsString('<current_draft>', $request->prompt);
        $this->assertStringContainsString('Shorten the opening.', $request->prompt);
        $this->assertSame(['user', 'assistant'], array_map(fn($message) => $message->role, $request->history));
    }

    public function testAFailedCallMarksTheSessionFailedAndKeepsTheDraft(): void
    {
        $this->saveType();
        $session = $this->startedSession();
        $session->draft = self::DRAFT;
        $this->plugin->sessions->save($session);

        $this->fake->respond('writer', fn() => throw new \RuntimeException('The provider is overloaded.'));

        $this->runTurn($session);

        $session = $this->plugin->sessions->find($session->id);
        $this->assertSame(Session::FAILED, $session->status);
        $this->assertSame('The provider is overloaded.', $session->error);
        $this->assertSame(self::DRAFT, $session->draft);
    }

    public function testAMessageCannotBeSentWhileTheLastOneIsBeingAnswered(): void
    {
        $this->signIn();
        $this->saveType();
        $session = $this->startedSession();

        $this->assertSame(409, $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Hello?'])['status']);

        $session->status = Session::IDLE;
        $this->plugin->sessions->save($session);

        $this->assertSame(Session::WORKING, $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Shorten it.'])['data']['status']);
        $this->assertCount(1, $this->queued(RunSessionTurn::class));
    }

    public function testTheDraftIsLaidOutForReadingWithTheModelsHtmlEscaped(): void
    {
        $this->signIn();
        $this->saveType();

        $session = $this->sessionWithDraft(self::DRAFT . "\n  - type: carousel\n  - type: longForm\n    content: |\n      <script>alert(1)</script> and [a link](javascript:alert(1)).");

        $preview = $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data']['preview'];

        $this->assertSame(['title', 'summary', 'pageBuilder'], array_column($preview, 'handle'));

        $blocks = $preview[2]['items'];
        $this->assertSame(['Hero', 'LongForm', 'Related', 'carousel', 'LongForm'], array_column($blocks, 'label'));
        $this->assertSame([true, true, true, false, true], array_column($blocks, 'known'));
        $this->assertStringContainsString('<strong>different</strong>', $blocks[1]['fields'][0]['html']);
        $this->assertStringNotContainsString('<script>', $blocks[4]['fields'][0]['html']);
        $this->assertStringNotContainsString('href="javascript:', $blocks[4]['fields'][0]['html']);
    }

    public function testWritingCanBeEditedWhereItIsShown(): void
    {
        $this->signIn();
        $this->saveType();
        $session = $this->sessionWithDraft(self::DRAFT);

        $preview = $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data']['preview'];
        $content = $preview[2]['items'][1]['fields'][0];

        // Each piece of writing says where it sits, so it can be edited there.
        $this->assertSame(['title'], $preview[0]['path']);
        $this->assertTrue($preview[0]['editable']);
        $this->assertSame(['pageBuilder', 1, 'content'], $content['path']);
        $this->assertTrue($content['editable']);
        $this->assertFalse($preview[2]['editable']);

        // Rich text comes back as the HTML that was edited and is kept as markdown.
        $detail = $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => json_encode($content['path']), 'format' => 'html', 'value' => '<h2>Why do quotes differ?</h2><p>They are for <b>different</b> sites.</p>'])['data'];

        $this->assertStringContainsString("## Why do quotes differ?\n\n      They are for **different** sites.", $detail['draft']);
        $this->assertStringContainsString('<strong>different</strong>', $detail['preview'][2]['items'][1]['fields'][0]['html']);

        $detail = $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => '["summary"]', 'format' => 'text', 'value' => "Why prices vary: an honest answer"])['data'];
        $this->assertSame('Why prices vary: an honest answer', \nineteenninetyfour\ghostwriter\drafts\Draft::parse($detail['draft'])->data['summary']);

        // Only writing, only where it exists, and not while the writer is at work.
        $this->assertSame(422, $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => '["pageBuilder"]', 'value' => 'x'])['status']);
        $this->assertSame(422, $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => '["nowhere", 3]', 'value' => 'x'])['status']);

        $session = $this->plugin->sessions->find($session->id);
        $session->status = Session::WORKING;
        $this->plugin->sessions->save($session);
        $this->assertSame(409, $this->action('ghostwriter/sessions/edit-field', ['id' => $session->id, 'path' => '["title"]', 'value' => 'x'])['status']);
    }

    public function testUsingTheDraftFillsTheEntrysDraftAndPublishesNothing(): void
    {
        $this->signIn(admin: true);
        $this->saveType();
        $target = $this->newDraft($this->articles);

        $session = $this->sessionWithDraft(self::DRAFT . "\n  - type: carousel", $target);

        $response = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);

        $this->assertSame(200, $response['status'], json_encode($response['data']));
        $this->assertStringContainsString('"carousel" cannot go in', $response['data']['notes'][0]);

        // Still an unpublished draft, now holding the writing.
        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();

        $this->assertTrue($draft->getIsUnpublishedDraft());
        $this->assertSame('What Does a Website Cost?', $draft->title);
        $this->assertSame('Why quotes vary, and what moves the number.', $draft->getFieldValue('summary'));

        $blocks = $draft->getFieldValue('pageBuilder')->status(null)->all();
        $this->assertSame(['hero', 'longForm', 'related'], array_map(fn($block) => $block->getType()->handle, $blocks));
        $this->assertStringContainsString('<strong>different</strong>', (string) $blocks[1]->getFieldValue('content'));

        // A house default the draft left out: the related block's usual heading.
        $this->assertSame('More articles', $blocks[2]->getFieldValue('heading'));

        // Nothing new was published in the section.
        $this->assertSame(3, (int) Entry::find()->section('articles')->count());
        $this->assertSame('in_form', (new Presenter())->summary($this->plugin->sessions->find($session->id))['stage']);
    }

    public function testANeoDraftIsPutIntoTheEntryWithItsChildBlocks(): void
    {
        $this->signIn(admin: true);
        $this->makeNewsArticle('Launch', ['We launched a fabric collection.']);
        $this->makeNewsArticle('Award', ['Our founder received an award.']);

        $target = $this->newDraft($this->news);
        $session = Session::start(ContentType::GENERIC . 'news', ['subject' => 'A new collection.']);
        $session->elementId = $target->id;
        $session->draft = "title: Fresh News\nnewsBuilder:\n  - type: assetSingle\n  - type: textWithAsset\n    children:\n      - type: text\n        richText: |\n          ### Fresh\n\n          We have news.\n  - type: spacer";
        $this->plugin->sessions->save($session);

        $response = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);

        $this->assertSame(200, $response['status'], json_encode($response['data']));

        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $blocks = $draft->getFieldValue('newsBuilder')->status(null)->all();

        $this->assertSame([['assetSingle', 1], ['textWithAsset', 1], ['text', 2], ['spacer', 1]], array_map(fn($block) => [$block->getType()->handle, (int) $block->level], $blocks));
        $this->assertStringContainsString('<h3>Fresh</h3>', (string) $blocks[2]->getFieldValue('richText'));

        // House defaults: the usual padding and spacer height.
        $this->assertSame(40, (int) $blocks[1]->getFieldValue('paddingTop'));
        $this->assertSame(80, (int) $blocks[3]->getFieldValue('height'));
    }

    public function testASessionIsFinishedOnceItsEntryIsSaved(): void
    {
        $this->signIn(admin: true);
        $this->saveType();
        $target = $this->newDraft($this->articles);
        $session = $this->sessionWithDraft(self::DRAFT, $target);

        $stage = fn() => array_intersect_key((new Presenter())->summary($this->plugin->sessions->find($session->id)), ['stage' => 1, 'finished' => 1]);

        $this->assertSame(['stage' => 'draft', 'finished' => false], $stage());

        $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(['stage' => 'in_form', 'finished' => false], $stage());

        // The person saves the entry: the draft becomes the entry itself.
        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $draft->enabled = false;
        Craft::$app->getDrafts()->applyDraft($draft);
        $this->assertSame(['stage' => 'saved', 'finished' => true], $stage());

        $entry = Entry::find()->id($target->id)->status(null)->one();
        $entry->enabled = true;
        Craft::$app->getElements()->saveElement($entry);
        $this->assertSame(['stage' => 'published', 'finished' => true], $stage());

        // Finished pieces are not offered to carry on with.
        $this->assertSame([], $this->action('ghostwriter/sections/show', ['section' => 'articles'], 'GET')['data']['sessions']);
    }

    public function testADraftThatDoesNotParseIsReportedNotApplied(): void
    {
        $this->signIn(admin: true);
        $this->saveType();
        $target = $this->newDraft($this->articles);
        $session = $this->sessionWithDraft("- not\n- a draft", $target);

        $this->assertStringContainsString('should be a list of fields', $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data']['draftProblem']);
        $this->assertSame(422, $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id])['status']);
    }

    public function testADraftCannotBeUsedOnAnEntryThePersonMayNotEdit(): void
    {
        $this->saveType();
        $this->signIn(admin: true);
        $target = $this->newDraft($this->articles);
        $session = $this->sessionWithDraft(self::DRAFT, $target);

        $this->signIn();

        $this->assertSame(403, $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id])['status']);
        $this->assertSame(404, $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $this->entry('One')->id + 9999])['status']);
    }

    public function testTheButtonAppearsOnNewEntriesInSectionsItWritesFor(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(true);

        $this->signIn(admin: true);
        $this->assertStringContainsString('Write with Ghostwriter', Launcher::buttonFor($this->newDraft($this->articles)));

        // An entry that already exists is edited instead.
        $this->assertStringContainsString('Edit with Ghostwriter', Launcher::buttonFor($this->entry('One')));

        // Not in a section it does not write for.
        $this->plugin->getSettings()->sections = ['press'];
        $this->assertSame('', Launcher::buttonFor($this->newDraft($this->articles)));

        // Not for someone without the permission.
        $this->plugin->getSettings()->sections = [];
        $draft = $this->newDraft($this->articles);
        $this->signIn(permitted: false, admin: false);
        $this->assertSame('', Launcher::buttonFor($draft));
    }

    public function testTheButtonCarriesOnWithTheEntrysOwnConversation(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->signIn(admin: true);
        $this->saveType();

        $target = $this->newDraft($this->articles);
        $other = $this->newDraft($this->articles);
        $session = $this->sessionWithDraft(self::DRAFT, $target);

        $config = function(Entry $entry): array {
            $view = Craft::$app->getView();
            $view->clear();
            Launcher::buttonFor($entry);
            $js = implode("\n", array_merge(...array_values($view->js ?: [[]])));
            preg_match('/new Ghostwriter\.Launcher\((\{.*\})\);/s', $js, $m);

            return json_decode($m[1], true);
        };

        // Reloading this entry, or coming back to it, finds its conversation.
        $this->assertSame($session->id, $config($target)['current']);
        $this->assertNull($config($other)['current']);
    }

    public function testATypeIsEditedAndCanBeDeleted(): void
    {
        $this->signIn();
        $this->saveType();

        $this->action('ghostwriter/types/save', [
            'handle' => 'project',
            'title' => 'Project article',
            'description' => 'Rewritten.',
            'questions' => [
                ['label' => 'Who was it for?', 'handle' => 'who', 'type' => 'text', 'required' => '1', 'instructions' => 'A description will do.'],
                ['label' => '', 'handle' => 'empty'],
            ],
            'guidance' => "Open on the reader.\n\nThen the project.",
            'checklist' => "No invented figures.\n\n",
            'examples' => [(string) $this->entry('One')->id],
        ]);

        $type = $this->plugin->types->find('project');

        $this->assertSame('Project article', $type->title);
        $this->assertSame('articles', $type->section);
        $this->assertSame([['handle' => 'who', 'label' => 'Who was it for?', 'instructions' => 'A description will do.', 'type' => 'text', 'required' => true]], $type->questions);
        $this->assertSame(['No invented figures.'], $type->checklist);
        $this->assertSame([$this->entry('One')->id], $type->examples);

        // The built-in general brief has nothing to edit.
        $this->assertSame(404, $this->action('ghostwriter/types/save', ['handle' => 'any:articles', 'title' => 'X'])['status']);

        $this->action('ghostwriter/types/delete', ['handle' => 'project']);
        $this->assertNull($this->plugin->types->find('project'));
    }

    private function saveType(): ContentType
    {
        return $this->plugin->types->save(ContentType::fromArray('project', [
            'title' => 'Article',
            'description' => 'A project write-up.',
            'section' => 'articles',
            'questions' => [
                ['handle' => 'what', 'label' => 'What was built?', 'type' => 'textarea', 'required' => true],
                ['handle' => 'avoid', 'label' => 'What must not appear?', 'type' => 'textarea'],
            ],
            'guidance' => 'Open on the reader. Two sections.',
            'checklist' => ['Every fact comes from the brief.'],
        ]));
    }

    private function startedSession(): Session
    {
        $type = $this->plugin->types->find('project');

        $session = Session::start('project', ['what' => 'A faceted search.']);
        $session->addMessage('user', $this->plugin->studio->brief($type, $session));
        $session->status = Session::WORKING;

        return $this->plugin->sessions->save($session);
    }

    private function sessionWithDraft(string $draft, ?Entry $target = null): Session
    {
        $session = $this->startedSession();
        $session->draft = $draft;
        $session->status = Session::IDLE;
        $session->elementId = $target?->id;

        return $this->plugin->sessions->save($session);
    }

    private function runTurn(Session $session): void
    {
        (new RunSessionTurn(['sessionId' => $session->id]))->execute(null);
    }

    private function entry(string $title): Entry
    {
        return Entry::find()->section('articles')->title($title)->status(null)->one();
    }
}
