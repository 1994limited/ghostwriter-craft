<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use craft\elements\Entry;
use nineteenninetyfour\ghostwriter\jobs\AnalyseSection;
use nineteenninetyfour\ghostwriter\jobs\SuggestKinds;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\KindSuggestions;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Ghostwriter suggesting the kinds of content each section holds, on request
 * and by itself, for a person to learn or turn down.
 */
class KindsTest extends TestCase
{
    use Sites;

    protected function _before(): void
    {
        parent::_before();

        $this->makeNewsSection();
        $this->makePressSection();

        $this->makeNewsArticle('Launch of a fabric collection', ['We launched a collection with a partner we admire.'], '2026-01-04');
        $this->makeNewsArticle('A new day bed', ['Our day bed is now on sale.'], '2026-01-03');
        $this->makeNewsArticle('Lifetime achievement award', ['Our founder received an award.'], '2026-01-02');
        $this->makeNewsArticle('Rooftop row-off', ['Join us for a charity row on the roof.'], '2026-01-01');
    }

    public function testKindsAreSuggestedFromWhatTheSectionHolds(): void
    {
        [$launch, $bed, $award, $row] = $this->news();
        $other = $this->makeEntry($this->press, 'Elsewhere');

        $this->plugin->types->save($this->plugin->types->make('award', ['title' => 'Award news', 'section' => 'news', 'questions' => [['handle' => 'q', 'label' => 'Q']]]));
        $this->plugin->types->changeSuggestions('news', fn(KindSuggestions $state) => $state->dismissed = ['Recruitment']);

        $this->fake->respond('kind-finder', "<kinds>\n- title: Product launch\n  description: Announces something new from the studio.\n  why: Two entries announce a product: what it is and where to get it.\n  examples: [{$launch->id}, {$bed->id}, {$other->id}]\n- title: Event\n  description: Invites readers to something happening.\n  why: Only one entry.\n  examples: [{$row->id}]\n- title: Award news\n  examples: [{$award->id}, {$launch->id}]\n- title: Recruitment\n  examples: [{$award->id}, {$launch->id}]\n</kinds>");

        (new SuggestKinds(['sections' => ['news']]))->execute(null);

        $state = $this->plugin->types->suggestions('news');

        // An entry from another section is dropped; a kind with one example,
        // or one already taught or turned down, is not suggested.
        $this->assertSame('idle', $state->status);
        $this->assertSame(['Product launch'], array_column($state->suggestions, 'title'));
        $this->assertSame([$launch->id, $bed->id], $state->suggestions[0]['examples']);
        $this->assertSame('newsArticle', $state->suggestions[0]['entryType']);
        $this->assertSame(4, $state->records);
        $this->assertNotNull($state->checkedAt);

        // It was shown each entry: its ID, how it is built and how it opens.
        $request = $this->fake->prompted('kind-finder')[0];
        $this->assertStringContainsString("- id {$launch->id} · \"Launch of a fabric collection\" · built as: assetSingle, textWithAsset, spacer · opens: \"### Launch of a fabric collection", $request->prompt);
        $this->assertStringContainsString('- Award news', $request->instructions);
        $this->assertStringContainsString('- Recruitment', $request->instructions);
    }

    public function testASuggestionSaysWhyAndWhereItWasSeen(): void
    {
        [$launch, $bed] = $this->news();
        $this->suggest('news', [['title' => 'Product launch', 'description' => 'Announces something new.', 'why' => 'Two entries announce a product.', 'examples' => [$launch->id, $bed->id], 'entryType' => null]], 4);
        $this->signIn();

        $suggestion = $this->plugin->types->presented('news')[0];
        $this->assertSame('Two entries announce a product.', $suggestion['why']);
        $this->assertSame(['Launch of a fabric collection', 'A new day bed'], $suggestion['exampleTitles']);

        $response = $this->action('ghostwriter/dashboard/index', method: 'GET');
        \Craft::$app->getView()->setTemplateMode(\craft\web\View::TEMPLATE_MODE_CP);
        $html = \Craft::$app->getView()->renderPageTemplate($response['data']['template'], $response['data']['variables'], \craft\web\View::TEMPLATE_MODE_CP);

        $this->assertStringContainsString('Two entries announce a product.', $html);
        $this->assertStringContainsString('For example: “Launch of a fabric collection”, “A new day bed”', $html);

        // And on Get started.
        $kinds = array_column($this->plugin->onboarding->details()['kinds'], null, 'handle');
        $this->assertSame(['Launch of a fabric collection', 'A new day bed'], $kinds['news']['suggestions'][0]['exampleTitles']);
    }

    public function testTextWithNonBreakingSpacesAndCurlyQuotesIsSentIntact(): void
    {
        // Pasted copy is full of these, and a byte-wise whitespace match
        // splits them in half.
        $this->makeNewsArticle('Winch’s 40th', ["Andrew\u{00A0}Winch’s studio celebrates forty\u{00A0}years —\u{00A0}“extraordinary design”."], '2026-01-05');

        $this->fake->respond('kind-finder', '<kinds></kinds>');

        (new SuggestKinds(['sections' => ['news']]))->execute(null);

        $prompt = $this->fake->prompted('kind-finder')[0]->prompt;

        $this->assertTrue(mb_check_encoding($prompt, 'UTF-8'));
        $this->assertStringContainsString('Andrew Winch’s studio celebrates forty years — “extraordinary design”.', $prompt);
        $this->assertNotFalse(json_encode($prompt));
    }

    public function testAFailedLookIsReported(): void
    {
        $this->fake->respond('kind-finder', 'I would rather not.');

        (new SuggestKinds(['sections' => ['news']]))->execute(null);

        $this->assertSame('failed', $this->plugin->types->suggestions('news')->status);
        $this->assertStringContainsString('did not come back with any kinds', $this->plugin->types->suggestions('news')->error);
    }

    public function testOpeningTheDashboardAsksTheModelNothing(): void
    {
        $this->signIn();

        // News has entries to read and has never been looked at, and the
        // setting is on: still, opening the dashboard queues nothing.
        $this->assertTrue($this->plugin->getSettings()->suggestKindsAutomatically);
        $this->assertSame(200, $this->action('ghostwriter/dashboard/index', method: 'GET')['status']);
        $this->assertSame([], $this->queued(SuggestKinds::class));
        $this->assertSame('idle', $this->plugin->types->suggestions('news')->status);
        $this->assertSame([], $this->fake->prompted('kind-finder'));
    }

    public function testGetStartedLooksByItselfOnceAndAgainAfterNewEntries(): void
    {
        $this->signIn();
        $this->assertTrue($this->action('ghostwriter/setup/status', method: 'GET')['data']['details']['autoKinds']);

        // News has entries to read; Press has none yet.
        $this->assertSame('working', $this->action('ghostwriter/sections/suggest-kinds', ['due' => 1])['data']['status']);
        $jobs = $this->queued(SuggestKinds::class);
        $this->assertCount(1, $jobs);
        $this->assertSame(['news'], $jobs[0]->sections);
        $this->assertSame('working', $this->plugin->types->suggestions('news')->status);

        // Looked at, it is left alone until enough has been published since.
        $this->suggest('news', [], 4);
        $this->assertSame('idle', $this->action('ghostwriter/sections/suggest-kinds', ['due' => 1])['data']['status']);
        $this->assertCount(1, $this->queued(SuggestKinds::class));

        $this->plugin->types->changeSuggestions('news', fn(KindSuggestions $state) => $state->records = 4 - KindSuggestions::RECHECK_AFTER);
        $this->action('ghostwriter/sections/suggest-kinds', ['due' => 1]);
        $this->assertCount(2, $this->queued(SuggestKinds::class));
    }

    public function testGetStartedLooksOnlyWithTheSettingOn(): void
    {
        $this->signIn();

        $this->plugin->getSettings()->suggestKindsAutomatically = false;
        $this->assertFalse($this->action('ghostwriter/setup/status', method: 'GET')['data']['details']['autoKinds']);
        $this->assertSame('idle', $this->action('ghostwriter/sections/suggest-kinds', ['due' => 1])['data']['status']);
        $this->assertSame([], $this->queued(SuggestKinds::class));

        // Asked for with a click, it still looks.
        $this->assertSame('working', $this->action('ghostwriter/sections/suggest-kinds')['data']['status']);
        $this->assertCount(1, $this->queued(SuggestKinds::class));
    }

    public function testSuggestionsCanBeAskedForLearnedOrTurnedDown(): void
    {
        $this->signIn();
        [$launch, $bed] = $this->news();

        $this->assertSame('working', $this->action('ghostwriter/sections/suggest-kinds', ['section' => 'news'])['data']['status']);
        $this->assertSame(['news'], $this->queued(SuggestKinds::class)[0]->sections);

        $this->suggest('news', [
            ['title' => 'Product launch', 'description' => 'New things.', 'why' => 'Two of them.', 'examples' => [$launch->id, $bed->id], 'entryType' => null],
            ['title' => 'Recruitment', 'description' => 'Jobs.', 'why' => 'Two of them.', 'examples' => [$launch->id, $bed->id], 'entryType' => null],
        ], 4);

        [$product, $recruitment] = $this->plugin->types->suggestions('news')->suggestions;

        // Learned: the job is given its name and examples, and it leaves the list.
        $this->action('ghostwriter/sections/learn-kind', ['section' => 'news', 'id' => $product['id']]);

        $job = $this->queued(AnalyseSection::class)[0];
        $this->assertSame('Product launch', $job->title);
        $this->assertSame([$launch->id, $bed->id], $job->examples);

        // Turned down: it leaves the list and is remembered.
        $this->action('ghostwriter/sections/dismiss-kind', ['section' => 'news', 'id' => $recruitment['id']]);

        $state = $this->plugin->types->suggestions('news');
        $this->assertSame([], $state->suggestions);
        $this->assertSame(['Recruitment'], $state->dismissed);
    }

    public function testEverySuggestionCanBeLearnedInOneGo(): void
    {
        $this->signIn();
        [$launch, $bed, $award, $row] = $this->news();

        $this->suggest('news', [
            ['title' => 'Product launch', 'description' => '', 'why' => '', 'examples' => [$launch->id, $bed->id], 'entryType' => null],
            ['title' => 'Award news', 'description' => '', 'why' => '', 'examples' => [$award->id, $launch->id], 'entryType' => null],
            ['title' => 'Event', 'description' => '', 'why' => '', 'examples' => [$row->id, $bed->id], 'entryType' => null],
        ], 4);

        $this->assertSame('working', $this->action('ghostwriter/sections/learn-all-kinds', ['section' => 'news'])['data']['status']);

        // One job, every kind in it, and the list emptied.
        $jobs = $this->queued(AnalyseSection::class);
        $this->assertCount(1, $jobs);
        $this->assertSame(['Product launch', 'Award news', 'Event'], array_column($jobs[0]->kinds, 'title'));
        $this->assertSame([], $this->plugin->types->suggestions('news')->suggestions);

        // Nothing is left to learn a second time.
        $this->assertSame(422, $this->action('ghostwriter/sections/learn-all-kinds', ['section' => 'news'])['status']);

        // One kind going wrong does not stop the others.
        $type = fn(string $title) => "<type>\ntitle: {$title}\ndescription: x\nquestions:\n  - handle: what\n    label: What?\nguidance: Short.\n</type>";
        $this->fake->respond('type-analyst', $type('Product launch'), 'Not readable.', 'Still not readable.', $type('Event'));

        $jobs[0]->execute(null);

        $this->assertSame(['Event', 'Product launch'], array_values(array_map(fn($type) => $type->title, $this->plugin->types->forSection('news'))));
        $this->assertStringContainsString('"Award news"', $this->fake->prompted('type-analyst')[1]->prompt);
        $this->assertStringContainsString('Your answer could not be read: there was no <type> block.', $this->fake->prompted('type-analyst')[2]->prompt);

        $state = $this->plugin->types->analysis('news');
        $this->assertSame('failed', $state->status);
        $this->assertStringStartsWith('Award news: The analysis came back in a form that could not be read.', $state->error);
    }

    public function testThePreviewRendersMarkdownAndEscapesHtml(): void
    {
        $this->signIn();

        $html = $this->action('ghostwriter/preview/markdown', ['markdown' => "## Who\n\nWe, to **you**.\n\n<script>alert(1)</script>"])['data']['html'];

        $this->assertStringContainsString('<h2>Who</h2>', $html);
        $this->assertStringContainsString('<strong>you</strong>', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * @return Entry[] Newest first.
     */
    private function news(): array
    {
        return Entry::find()->section('news')->orderBy(['postDate' => SORT_DESC])->all();
    }

    /**
     * What a look at a section found, as SuggestKinds keeps it.
     *
     * @param array<int, array<string, mixed>> $suggestions
     */
    private function suggest(string $section, array $suggestions, int $live): void
    {
        $this->plugin->types->changeSuggestions($section, fn(KindSuggestions $state) => $state->store($suggestions, $live));
    }
}
