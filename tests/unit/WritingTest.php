<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\Entry;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Studio\Brief;
use nineteenninetyfour\ghostwriter\http\Presenter;
use nineteenninetyfour\ghostwriter\jobs\AnalyseSection;
use nineteenninetyfour\ghostwriter\jobs\FillBrief;
use nineteenninetyfour\ghostwriter\jobs\RunSessionTurn;
use nineteenninetyfour\ghostwriter\Launcher;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Learning a section, the brief in the conversation, and handing the
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
        $this->assertSame('articles', $type->group);
        $this->assertSame(['what', 'avoid'], array_column($type->questions, 'handle'));
        $this->assertSame('idle', $this->plugin->types->analysis('articles')->status);
        $this->assertStringContainsString('section: articles', (string) $this->plugin->store->document('type', 'project-article'));

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
        $this->assertSame('failed', $this->plugin->types->analysis('articles')->status);
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
        $this->assertSame('idle', $this->plugin->types->analysis('articles')->status);

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

        $this->assertSame('working', $response['data']['state']['status']);

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

    public function testTheConversationAsksForTheDetailsAndFillsInTheBrief(): void
    {
        $user = $this->signIn(admin: true);
        $this->saveType();
        $draft = $this->newDraft($this->articles);

        // Nothing to say yet: nothing is started.
        $this->assertSame(422, $this->action('ghostwriter/sessions/open', ['type' => 'project', 'message' => ' ', 'elementId' => $draft->id])['status']);
        $this->assertSame([], $this->plugin->sessions->all());

        $opened = $this->action('ghostwriter/sessions/open', [
            'type' => 'project',
            'message' => 'Faceted search for a kitchen appliance maker. Filters by what the cook needs.',
            'elementId' => $draft->id,
            'examples' => [$this->entry('Two')->id, $this->makeEntry($this->press, 'Elsewhere')->id],
        ])['data'];

        // The question, the reply, and Ghostwriter filling in the brief.
        $this->assertSame('filling', $opened['stage']);
        $this->assertSame(Session::WORKING, $opened['status']);
        $this->assertSame([['ask', 'assistant', 'What’s it called, and what should it say? A line or two is plenty.'], ['details', 'user', 'Faceted search for a kitchen appliance maker. Filters by what the cook needs.']], array_map(fn(array $m) => [$m['step'], $m['role'], $m['content']], $opened['messages']));
        $this->assertCount(1, $this->queued(FillBrief::class));
        $this->assertSame([], $this->queued(RunSessionTurn::class));

        $session = $this->plugin->sessions->find($opened['id']);
        $this->assertSame($user->id, $session->startedBy);
        $this->assertSame($draft->id, $session->recordId);
        $this->assertSame([$this->entry('Two')->id], $session->examples, 'Only entries from its own section.');

        $this->fake->respond('brief-filler', "<title>Faceted search</title>\n<brief>\nwhat: |\n  A search that narrows the range by what the cook needs.\n  [Add: the client and what changed after launch]\navoid: Client names.\nmade_up: ignored\n</brief>");
        $this->fillBrief($session);

        $detail = $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data'];

        $this->assertSame('proposed', $detail['stage']);
        $this->assertSame(Session::IDLE, $detail['status']);
        $this->assertFalse($detail['waitingOnYou'], 'The card is answered with its own buttons.');
        $this->assertSame('Faceted search', $detail['title']);
        $this->assertSame([
            'title' => 'Faceted search',
            'answers' => ['what' => "A search that narrows the range by what the cook needs.\n[Add: the client and what changed after launch]", 'avoid' => 'Client names.'],
            'examples' => [$this->entry('Two')->id],
            'attempt' => 1,
            'open' => ['what'],
            'agreed' => false,
        ], $detail['card']);
        $this->assertSame(['ask', 'details', 'card'], array_column($detail['messages'], 'step'));
        $this->assertSame('Here’s the brief. Change anything that isn’t right, then start writing. Anything in [square brackets] is for you to fill in.', end($detail['messages'])['content']);

        // It was given the reply, the kind's questions and what the section already has.
        $request = $this->fake->prompted('brief-filler')[0];
        $this->assertStringContainsString('Filters by what the cook needs.', $request->prompt);
        $this->assertStringContainsString('- Three', $request->instructions);

        // Nothing is written until the person agrees.
        $this->assertSame([], $this->queued(RunSessionTurn::class));
        $this->assertSame(409, $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Write it now.'])['status']);
    }

    public function testWithNothingTickedTheBriefTicksEntriesToModelItOn(): void
    {
        $this->signIn(admin: true);
        $this->saveType();
        $draft = $this->newDraft($this->articles);
        $hidden = $this->makeArticle('Hidden', 'A paragraph about something not yet live, which says enough to count.', live: false);

        $opened = $this->action('ghostwriter/sessions/open', ['type' => 'project', 'message' => 'A February jobs guide.', 'elementId' => $draft->id])['data'];
        $session = $this->plugin->sessions->find($opened['id']);
        $this->assertSame([], $session->examples);

        $two = $this->entry('Two')->id;
        $this->fake->respond('brief-filler', "<title>February jobs</title>\n<brief>\nwhat: A jobs guide.\n</brief>\n<examples>{$two}, {$hidden->id}, 999999</examples>");
        $this->fillBrief($session);

        // Live entries are offered by ID; the rest by title only.
        $request = $this->fake->prompted('brief-filler')[0];
        $this->assertStringContainsString("- Two [id: {$two}]", $request->instructions);
        $this->assertStringNotContainsString("[id: {$hidden->id}]", $request->instructions);
        $this->assertStringContainsString('choose for them', $request->prompt);

        $this->assertSame([$two], $this->action('ghostwriter/sessions/show', ['id' => $session->id], 'GET')['data']['card']['examples']);
    }

    public function testThePersonsTicksWinOverTheBriefsChoice(): void
    {
        $this->signIn(admin: true);
        $this->saveType();
        $draft = $this->newDraft($this->articles);
        $one = $this->entry('One')->id;

        $opened = $this->action('ghostwriter/sessions/open', ['type' => 'project', 'message' => 'A February jobs guide.', 'elementId' => $draft->id, 'examples' => [$one]])['data'];
        $this->fake->respond('brief-filler', "<title>February jobs</title>\n<brief>\nwhat: A jobs guide.\n</brief>\n<examples>{$this->entry('Two')->id}</examples>");
        $this->fillBrief($this->plugin->sessions->find($opened['id']));

        $this->assertSame([$one], $this->action('ghostwriter/sessions/show', ['id' => $opened['id']], 'GET')['data']['card']['examples']);
    }

    public function testTryAgainKeepsTheAnswersThePersonChanged(): void
    {
        $this->signIn();
        $this->saveType();
        $session = $this->proposed();

        $this->fake->respond('brief-filler', "<title>Search that listens</title>\n<brief>\nwhat: A search built around the cook's questions.\navoid: Jargon.\n</brief>");

        $again = $this->action('ghostwriter/sessions/try-again', ['id' => $session->id, 'title' => 'Faceted search', 'answers' => ['avoid' => 'Anything about pricing.']])['data'];

        $this->assertSame('filling', $again['stage']);
        $this->assertSame(['ask', 'details', 'card'], array_column($again['messages'], 'step'), '"Try again" itself is not shown.');
        $this->assertCount(1, $this->queued(FillBrief::class));

        $this->fillBrief($session);
        $card = (new Presenter())->detail($this->plugin->sessions->find($session->id))['card'];

        $this->assertSame(2, $card['attempt']);
        $this->assertSame('Faceted search', $card['title'], 'The working title the card had.');
        $this->assertSame(['what' => "A search built around the cook's questions.", 'avoid' => 'Anything about pricing.'], $card['answers']);
        $this->assertStringContainsString('`avoid`', $this->fake->prompted('brief-filler')[0]->prompt);

        // Only while a brief waits to be checked.
        $this->assertSame(409, $this->action('ghostwriter/sessions/try-again', ['id' => $this->startedSession()->id])['status']);
    }

    public function testLooksRightStartsWritingAndTheBriefStaysEditable(): void
    {
        $this->signIn();
        $this->saveType();
        $session = $this->proposed();

        // A required answer left empty is refused, as on the brief screen.
        $refused = $this->action('ghostwriter/sessions/agree', ['id' => $session->id, 'answers' => ['what' => ' ']]);
        $this->assertSame(422, $refused['status']);
        $this->assertArrayHasKey('what', $refused['data']['errors']);
        $this->assertSame([], $this->queued(RunSessionTurn::class));

        // Something left in square brackets is an answer: the writer asks about it.
        $agreed = $this->action('ghostwriter/sessions/agree', ['id' => $session->id, 'title' => 'Search that listens', 'answers' => ['what' => 'A faceted search. [Add: the client]'], 'examples' => []])['data'];

        $this->assertSame(Session::WORKING, $agreed['status']);
        $this->assertSame('writing', $agreed['stage']);
        $this->assertTrue($agreed['card']['agreed']);
        $this->assertSame([], $agreed['card']['examples']);
        $this->assertSame(['ask', 'details', 'card'], array_column($agreed['messages'], 'step'), 'The brief shows as the card, not as a message.');
        $this->assertCount(1, $this->queued(RunSessionTurn::class));

        $stored = $this->plugin->sessions->find($session->id);
        $this->assertSame(['what' => 'A faceted search. [Add: the client]', 'avoid' => 'Client names.'], $stored->answers);

        // The writer starts from the brief, without the question or the card.
        $this->fake->respond('writer', '<reply>1. Which client was it for?</reply>');
        $this->runTurn($stored);

        $writer = $this->fake->prompted('writer')[0];
        $this->assertStringContainsString('Search that listens', $writer->prompt);
        $this->assertStringContainsString('A faceted search. [Add: the client]', $writer->prompt);
        $this->assertStringNotContainsString('A line or two is plenty', $writer->prompt . json_encode($writer->history));

        $detail = (new Presenter())->detail($this->plugin->sessions->find($session->id));
        $this->assertSame('questions', $detail['stage']);
        $this->assertTrue($detail['waitingOnYou']);

        // "Show the brief", change it and save: no turn runs.
        $edited = $this->action('ghostwriter/sessions/edit-brief', ['id' => $session->id, 'answers' => ['avoid' => 'Prices.']])['data'];

        $this->assertSame('Prices.', $edited['card']['answers']['avoid']);
        $this->assertTrue($edited['card']['agreed']);
        $this->assertSame(Session::IDLE, $edited['status']);
        $this->assertCount(1, $this->queued(RunSessionTurn::class));
        $brief = array_values(array_filter($this->plugin->sessions->find($session->id)->messages, fn(array $m) => ($m['brief']['step'] ?? null) === 'agreed'));
        $this->assertStringContainsString('Prices.', $brief[0]['content'], 'The writer works from the changed brief.');

        // Not while Ghostwriter is working on the piece.
        $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'It was for Hearth & Co.']);
        $this->assertSame(409, $this->action('ghostwriter/sessions/edit-brief', ['id' => $session->id, 'answers' => ['avoid' => 'Nothing.']])['status']);
    }

    public function testTheWritersQuestionsAreAnsweredOneBoxEachAsOneMessage(): void
    {
        $this->signIn();
        $this->saveType();
        $session = $this->proposed();
        $this->action('ghostwriter/sessions/agree', ['id' => $session->id, 'answers' => ['what' => 'A faceted search.'], 'examples' => []]);

        $this->fake->respond('writer', "<reply>A few things only you know.</reply>\n<questions>\n- id: client\n  question: Which client was it for?\n  hint: Name, if they agreed\n- id: when\n  question: When did it launch?\n  kind: choice\n  options: [This year, Last year]\n  optional: true\n</questions>");
        $this->runTurn($this->plugin->sessions->find($session->id));

        $detail = (new Presenter())->detail($this->plugin->sessions->find($session->id));
        $this->assertTrue($detail['waitingOnYou']);
        $asked = end($detail['messages'])['asked'];
        $this->assertSame('A few things only you know.', $asked['intro']);
        $this->assertSame(['client', 'when'], array_column($asked['questions'], 'id'));
        $this->assertSame(['This year', 'Last year'], $asked['questions'][1]['options']);
        $this->assertFalse($asked['answered']);

        // Nothing answered: nothing sent.
        $this->assertSame(409, $this->action('ghostwriter/sessions/answers', ['id' => $session->id, 'answers' => ['client' => ' ']])['status']);
        $this->assertCount(1, $this->queued(RunSessionTurn::class));

        $sent = $this->action('ghostwriter/sessions/answers', ['id' => $session->id, 'answers' => ['client' => 'Harbour Books', 'when' => ''], 'more' => 'Keep it short.'])['data'];
        $this->assertSame(Session::WORKING, $sent['status']);
        $this->assertCount(2, $this->queued(RunSessionTurn::class));

        $stored = $this->plugin->sessions->find($session->id);
        $this->assertSame("Which client was it for? → Harbour Books\n\nWhen did it launch? → skipped\n\nAlso: Keep it short.", end($stored->messages)['content']);

        // The card now shows each answer, read-only, and who answered.
        $card = $sent['messages'][count($sent['messages']) - 2]['asked'];
        $this->assertTrue($card['answered']);
        $this->assertSame(['Harbour Books', null], array_column($card['questions'], 'answer'));
        $this->assertSame('you', $card['answeredBy']);
        $this->assertSame('Keep it short.', end($sent['messages'])['more']);
    }

    public function testAFailedFillIsTriedAgainAsAFill(): void
    {
        $this->signIn();
        $this->saveType();
        $session = $this->opened();

        $this->fake->respond('brief-filler', 'Sorry, I cannot help with that.');
        $this->fillBrief($session);

        $failed = $this->plugin->sessions->find($session->id);
        $this->assertSame(Session::FAILED, $failed->status);
        $this->assertSame('Ghostwriter could not fill in the brief from that. Try again, or say a little more about it.', $failed->error);

        $retried = $this->action('ghostwriter/sessions/retry', ['id' => $session->id])['data'];

        $this->assertSame('filling', $retried['stage']);
        $this->assertCount(1, $this->queued(FillBrief::class));
        $this->assertSame([], $this->queued(RunSessionTurn::class));
    }

    public function testAPieceWaitingForItsDetailsTakesThemAsAMessage(): void
    {
        $this->signIn();
        $this->saveType();

        $session = Session::start(Format::Craft, 'project', [], Craft::$app->getUser()->getId());
        $session = $this->plugin->domain->sessions()->open($session, $this->plugin->domain->viewer());

        $this->assertSame('details', (new Presenter())->detail($session)['stage']);

        $sent = $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'A guide to faceted search.'])['data'];

        $this->assertSame('filling', $sent['stage']);
        $this->assertCount(1, $this->queued(FillBrief::class));

        // Only once.
        $this->assertSame(409, $this->action('ghostwriter/sessions/message', ['id' => $session->id, 'message' => 'Again.'])['status']);
    }

    public function testAPieceFromTheBriefScreenStillShowsItsBrief(): void
    {
        $this->saveType();
        $session = $this->startedSession();
        $session->status = Session::IDLE;
        $session->addMessage('assistant', 'Here is the draft.');
        $detail = (new Presenter())->detail($session);

        $this->assertNull($detail['card']);
        $this->assertStringContainsString('A faceted search.', $detail['briefText']);
        $this->assertSame(['Here is the draft.'], array_column($detail['messages'], 'content'));
    }

    public function testTheWriterCanInterviewFirstAndDraftSecond(): void
    {
        $this->plugin->domain->saveGuide(Guide::VOICE, "# Tone of voice\n\nTwo punchlines at most.");
        $type = $this->saveType();
        $session = $this->startedSession();

        $this->fake->respond('writer', '<reply>1. Which projects can I cite?</reply>', "<reply>Here is the draft.</reply>\n<draft>\n" . self::DRAFT . "\n</draft>");

        $this->runTurn($session);

        $session = $this->plugin->sessions->find($session->id);
        $this->assertNull($session->draft);
        $this->assertSame('1. Which projects can I cite?', end($session->messages)['content']);

        // The panel is told the writer is waiting for an answer.
        $this->assertTrue((new Presenter())->detail($session)['waitingOnYou']);

        // Replies are shown as markdown, with any HTML in them escaped; what
        // the person typed is left as typed.
        $session->addMessage('assistant', "**Two** things:\n\n- the client\n- <script>alert(1)</script>");
        $messages = (new Presenter())->detail($session)['messages'];
        $this->assertStringContainsString('<strong>Two</strong>', end($messages)['html']);
        $this->assertStringContainsString('<li>the client</li>', end($messages)['html']);
        $this->assertStringContainsString('&lt;script&gt;', end($messages)['html']);
        $this->assertNotContains('user', array_column($messages, 'role'), 'The brief is behind "Show the brief".');
        array_pop($session->messages);
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
        $instructions = $this->plugin->studio->writerInstructions($type, $this->plugin->domain->guide(Guide::VOICE)->body);

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

    public function testAFailedTurnCanBeTriedAgainWithTheSameMessage(): void
    {
        $this->signIn();
        $this->saveType();
        $session = $this->startedSession();

        // Nothing has failed yet.
        $this->assertSame(409, $this->action('ghostwriter/sessions/retry', ['id' => $session->id])['status']);

        $this->fake->respond('writer', fn() => throw new \RuntimeException('The provider is overloaded.'));
        $this->runTurn($session);

        $messages = $this->plugin->sessions->find($session->id)->messages;
        $retried = $this->action('ghostwriter/sessions/retry', ['id' => $session->id]);

        $this->assertSame(200, $retried['status']);
        $this->assertSame(Session::WORKING, $retried['data']['status']);
        $this->assertNull($retried['data']['error']);
        $this->assertCount(1, $this->queued(RunSessionTurn::class));

        // The same conversation goes again; nothing is added to it.
        $this->assertSame($messages, $this->plugin->sessions->find($session->id)->messages);
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
        $this->assertSame('Why prices vary: an honest answer', \NineteenNinetyFour\Ghostwriter\Core\Text\Draft::parse($detail['draft'])->data['summary']);

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

    public function testANewEntryStartsUnpublishedSoItCanBeSavedAtOnce(): void
    {
        $this->signIn(admin: true);
        $this->saveType();
        $target = $this->newDraft($this->articles);
        $this->assertTrue($target->enabled, 'Craft makes new entries enabled by default.');

        $session = $this->sessionWithDraft(self::DRAFT, $target);
        $response = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);

        $this->assertSame(200, $response['status'], json_encode($response['data']));
        $this->assertContains('Ghostwriter drafts start unpublished. Switch on Enabled when you’re ready.', $response['data']['notes']);

        // The form shows Enabled off (the site's switch too: on a single
        // site Craft keeps it as Enabled alone); the editor can switch it on.
        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $this->assertFalse($draft->enabled);
        $this->assertFalse($draft->enabled && $draft->getEnabledForSite());

        // Saved as it is, it is a disabled entry, not a live one.
        Craft::$app->getDrafts()->applyDraft($draft);
        $this->assertSame(Entry::STATUS_DISABLED, Entry::find()->id($target->id)->status(null)->one()->getStatus());
    }

    public function testWithDraftsUnpublishedOffANewEntryKeepsItsDefault(): void
    {
        $this->signIn(admin: true);
        $this->saveType();
        $this->plugin->getSettings()->draftsUnpublished = false;
        $target = $this->newDraft($this->articles);

        $response = $this->action('ghostwriter/sessions/apply', ['id' => $this->sessionWithDraft(self::DRAFT, $target)->id, 'elementId' => $target->id]);

        $this->assertNotContains('Ghostwriter drafts start unpublished. Switch on Enabled when you’re ready.', $response['data']['notes']);
        $this->assertTrue(Entry::find()->id($target->id)->drafts(null)->status(null)->one()->enabled);
        $this->plugin->getSettings()->draftsUnpublished = true;
    }

    public function testANeoDraftIsPutIntoTheEntryWithItsChildBlocks(): void
    {
        $this->signIn(admin: true);
        $this->makeNewsArticle('Launch', ['We launched a fabric collection.']);
        $this->makeNewsArticle('Award', ['Our founder received an award.']);

        $target = $this->newDraft($this->news);
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'news', ['subject' => 'A new collection.'], Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: Fresh News\nnewsBuilder:\n  - type: assetSingle\n  - type: textWithAsset\n    children:\n      - type: text\n        richText: |\n          ### Fresh\n\n          We have news.\n  - type: spacer";
        $this->plugin->sessions->save($session);

        $response = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);

        $this->assertSame(200, $response['status'], json_encode($response['data']));

        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $blocks = $draft->getFieldValue('newsBuilder')->status(null)->all();

        $this->assertSame([['assetSingle', 1], ['textWithAsset', 1], ['text', 2], ['spacer', 1]], array_map(fn($block) => [$block->getType()->handle, (int) $block->level], $blocks));
        // The SEO pass starts a block's text at H2 under the title's H1: no level is skipped.
        $this->assertStringContainsString('<h2>Fresh</h2>', (string) $blocks[2]->getFieldValue('richText'));

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

        // Their own piece, for an entry they may not save.
        $this->signIn();
        $session = $this->sessionWithDraft(self::DRAFT, $target);

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

        // With its menu: the counted rows (hidden until a guide has a
        // count), the count on the menu's button; the button itself isn't
        // repeated in the menu, and the menu's button waits for a count.
        $html = Launcher::buttonFor($this->entry('One'), true);
        $this->assertStringContainsString('class="btngroup gw-launch"', $html);
        $this->assertStringContainsString('data-gw-menu-row="finish"', $html);
        $this->assertStringContainsString('data-gw-menu-row="suggest"', $html);
        $this->assertStringContainsString('data-gw-menu-total', $html);
        $this->assertStringContainsString('aria-label="More ways to edit with Ghostwriter"', $html);
        $this->assertSame(1, substr_count($html, 'Edit with Ghostwriter</span>'), 'Once, as the button.');
        $this->assertMatchesRegularExpression('/class="btn menubtn gw-menu-btn hidden"/', $html);

        // Not in a section it does not write for.
        $this->plugin->getSettings()->sections = ['press'];
        $this->assertSame('', Launcher::buttonFor($this->newDraft($this->articles)));

        // Not for someone without the permission.
        $this->plugin->getSettings()->sections = [];
        $draft = $this->newDraft($this->articles);
        $this->signIn(permitted: false, admin: false);
        $this->assertSame('', Launcher::buttonFor($draft));
    }

    public function testTheEntryIndexOffersWritingInSectionsItWritesFor(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(true);
        $view = Craft::$app->getView();
        $script = function() use ($view): string {
            $js = implode("\n", array_merge(...array_values($view->js ?: [[]])));
            $view->js = [];

            return $js;
        };

        $this->signIn(admin: true);
        Launcher::registerIndexButton();
        $js = $script();

        $this->assertStringContainsString('new Ghostwriter.IndexButton(', $js);
        $this->assertStringContainsString('ghostwriter\\/write\\/articles', $js);

        // Not for someone without the permission.
        $this->signIn(permitted: false, admin: false);
        Launcher::registerIndexButton();
        $this->assertStringNotContainsString('IndexButton', $script());
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
            preg_match('/new Ghostwriter\.Launcher\((\{.*\})\);$/m', $js, $m);

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
        $this->assertSame('articles', $type->group);
        $this->assertSame([['handle' => 'who', 'label' => 'Who was it for?', 'instructions' => 'A description will do.', 'type' => 'text', 'required' => true]], $type->questions);
        $this->assertSame(['No invented figures.'], $type->checklist);
        $this->assertSame([$this->entry('One')->id], $type->examples);

        // The built-in general brief has nothing to edit.
        $this->assertSame(404, $this->action('ghostwriter/types/save', ['handle' => 'any:articles', 'title' => 'X'])['status']);

        $this->action('ghostwriter/types/delete', ['handle' => 'project']);
        $this->assertNull($this->plugin->types->find('project'));
    }

    public function testAKindThatCannotBeSavedKeepsWhatWasTyped(): void
    {
        $this->signIn();
        $this->saveType();

        $response = $this->action('ghostwriter/types/save', [
            'handle' => 'project',
            'title' => '',
            'description' => 'Typed, not saved.',
            'questions' => [['label' => 'Who was it for?', 'type' => 'text']],
            'guidance' => 'New guidance.',
        ], json: false);

        $this->assertSame([], $response['data']);
        $this->assertSame('A project write-up.', $this->plugin->types->find('project')->description);

        // The form comes back with what was typed, not what is stored.
        $posted = Craft::$app->getUrlManager()->getRouteParams()['type'];
        $screen = $this->action('ghostwriter/types/edit', method: 'GET', params: ['handle' => 'project', 'type' => $posted])['data']['variables'];
        $this->assertSame('Typed, not saved.', $screen['type']->description);
        $this->assertSame('New guidance.', $screen['type']->guidance);
        $this->assertSame(['Who was it for?'], array_column($screen['questions'], 'label'));
    }

    public function testDeletingAKindSaysSo(): void
    {
        $this->signIn();
        $this->saveType();

        $this->action('ghostwriter/types/delete', ['handle' => 'project']);

        // Shown on the dashboard the screen goes to, though the request asked for JSON.
        $this->assertNull($this->plugin->types->find('project'));
        $this->assertSame('Kind deleted. Entries already written are not affected.', Craft::$app->getSession()->getSuccess());
    }

    private function saveType(): ContentType
    {
        return $this->plugin->types->save($this->plugin->types->make('project', [
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

        // As the brief screen started a piece before 1.6: the brief is the first message.
        $session = Session::start(Format::Craft, 'project', ['what' => 'A faceted search.'], Craft::$app->getUser()->getId());
        $session->addMessage('user', $this->plugin->studio->brief($type, new Brief('', $session->answers)));
        $session->status = Session::WORKING;

        return $this->plugin->sessions->save($session);
    }

    /**
     * A piece whose quick details have been given, being filled in.
     */
    private function opened(): Session
    {
        $draft = $this->newDraft($this->articles);
        $id = $this->action('ghostwriter/sessions/open', ['type' => 'project', 'message' => 'Faceted search for a kitchen appliance maker.', 'elementId' => $draft->id])['data']['id'];
        $this->clearQueue();

        return $this->plugin->sessions->find($id);
    }

    /**
     * A piece with its brief card waiting to be checked.
     */
    private function proposed(): Session
    {
        $session = $this->opened();

        $this->fake->respond('brief-filler', "<title>Faceted search</title>\n<brief>\nwhat: A search that narrows the range.\navoid: Client names.\n</brief>");
        $this->fillBrief($session);
        $this->fake->reset();

        return $this->plugin->sessions->find($session->id);
    }

    private function fillBrief(Session $session): void
    {
        (new FillBrief(['sessionId' => $session->id]))->execute(null);
    }

    private function sessionWithDraft(string $draft, ?Entry $target = null): Session
    {
        $session = $this->startedSession();
        $session->draft = $draft;
        $session->status = Session::IDLE;
        $session->recordId = $target?->id;

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
