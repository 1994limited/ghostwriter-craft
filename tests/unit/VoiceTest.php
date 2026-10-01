<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use craft\fields\PlainText;
use craft\models\Section;
use nineteenninetyfour\ghostwriter\jobs\GenerateVoiceGuide;
use nineteenninetyfour\ghostwriter\jobs\RefineVoiceGuide;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;
use nineteenninetyfour\ghostwriter\voice\VoiceState;

/**
 * Learning the site's voice from what it has published, and keeping the guide.
 */
class VoiceTest extends TestCase
{
    private const PARAGRAPH = 'When on-site search is done right, not only will it help your customers find the items they need but it will also give them that gentle nudge when making product decisions. This is a win all round when it comes to sales. However, offer up a complex product range and your customers will likely be left feeling overwhelmed and less likely to round up their journey. Those high end sales? Vanished. Challenge accepted.';

    private Section $articles;

    protected function _before(): void
    {
        parent::_before();

        $summary = $this->makeField(PlainText::class, 'summary', ['multiline' => true]);
        $body = $this->makeField(PlainText::class, 'body', ['multiline' => true]);
        $image = $this->makeField(PlainText::class, 'heroImage');
        $copy = $this->makeField(PlainText::class, 'copy', ['multiline' => true]);
        $caption = $this->makeField(PlainText::class, 'caption');

        $builder = $this->makeMatrix('pageBuilder', [
            $this->makeEntryType('longForm', [$copy], hasTitle: false),
            $this->makeEntryType('gallery', [$caption], hasTitle: false),
        ]);

        $this->articles = $this->makeSection('articles', [$this->makeEntryType('article', [$summary, $image, $body, $builder])]);

        $this->makeArticle('Intuitive Faceted Search', self::PARAGRAPH, '2026-01-02');
        $this->makeArticle('Dealer Finder', str_replace('search', 'finder', self::PARAGRAPH), '2026-01-01');
    }

    public function testTheScannerReadsProseAndLeavesThePlumbing(): void
    {
        $samples = $this->plugin->scanner->samples();

        $this->assertSame(['Intuitive Faceted Search', 'Dealer Finder'], array_column($samples, 'title'));
        $this->assertSame('articles', $samples[0]['section']);

        $text = $samples[0]['text'];

        // Rich text comes through as markdown; Matrix blocks are read in order.
        $this->assertStringContainsString("## The Problem\n\n" . self::PARAGRAPH, $text);
        $this->assertStringContainsString('> Challenge accepted.', $text);
        $this->assertStringContainsString('Property searches and recipe selection, among others.', $text);
        $this->assertStringContainsString('A summary line about Intuitive Faceted Search', $text);

        // References and switched-off blocks are not prose.
        $this->assertStringNotContainsString('/uploads/', $text);
        $this->assertStringNotContainsString('Switched off', $text);
    }

    public function testDraftsAndShortEntriesAreNotRead(): void
    {
        $this->makeArticle('Unpublished', self::PARAGRAPH, live: false);
        $this->makeEntry($this->articles, 'Contact', ['summary' => 'Call us any time on the number below.']);

        $this->assertSame(['Intuitive Faceted Search', 'Dealer Finder'], array_column($this->plugin->scanner->samples(), 'title'));
    }

    public function testGeneratingWritesTheGuideFromTheSamples(): void
    {
        $this->fake->respond('voice-analyst', "# Tone of voice\n\n## Who is talking, to whom\n\nWe, to you.");

        (new GenerateVoiceGuide())->execute(null);

        $this->assertStringContainsString('We, to you.', $this->plugin->voiceGuide->get());
        $this->assertSame(VoiceState::IDLE, $this->plugin->voiceState->get()['status']);
        $this->assertCount(2, $this->plugin->voiceState->get()['scanned']);
        $this->assertStringContainsString('We, to you.', (string) $this->plugin->store->document('guide', 'voice'));
        $this->assertFileDoesNotExist($this->workspace . '/guides/voice.md');

        $prompt = $this->fake->prompted('voice-analyst')[0];
        $this->assertStringContainsString('Those high end sales? Vanished.', $prompt->prompt);
        $this->assertStringContainsString('<sample number="1" collection="articles" title="Intuitive Faceted Search">', $prompt->prompt);
        $this->assertStringContainsString('Work only from the evidence in the samples.', $prompt->instructions);
    }

    public function testGeneratingWithNothingToReadFailsWithAReason(): void
    {
        (new GenerateVoiceGuide(['sections' => ['no-such-section']]))->execute(null);

        $state = $this->plugin->voiceState->get();

        $this->assertSame(VoiceState::FAILED, $state['status']);
        $this->assertStringContainsString('no published content long enough', $state['error']);
        $this->assertFalse($this->plugin->voiceGuide->exists());
        $this->assertSame([], $this->fake->prompted('voice-analyst'));
    }

    public function testAFailedCallIsReportedOnTheScreen(): void
    {
        $this->fake->respond('voice-analyst', fn() => throw new \RuntimeException('The provider is overloaded.'));

        (new GenerateVoiceGuide())->execute(null);

        $this->assertSame(['failed', 'The provider is overloaded.'], array_values(array_intersect_key($this->plugin->voiceState->get(), ['status' => 1, 'error' => 1])));

        // Reported once: the next visit starts clean.
        $this->plugin->voiceState->forgetFailure();
        $this->assertSame(VoiceState::IDLE, $this->plugin->voiceState->get()['status']);
    }

    public function testRefiningAppliesTheChangeAndRecordsTheReply(): void
    {
        $this->plugin->voiceGuide->save("# Tone of voice\n\nOriginal.");
        $this->plugin->voiceState->addMessage('user', 'Ban the word synergy.');

        $this->fake->respond('voice-editor', "<reply>Added it.</reply>\n<document>\n# Tone of voice\n\nNever say synergy.\n</document>");

        (new RefineVoiceGuide())->execute(null);

        $this->assertStringContainsString('Never say synergy.', $this->plugin->voiceGuide->get());
        $this->assertSame('Added it.', $this->plugin->voiceState->get()['messages'][1]['content']);

        $prompt = $this->fake->prompted('voice-editor')[0];
        $this->assertStringContainsString('Original.', $prompt->prompt);
        $this->assertStringContainsString('Request: Ban the word synergy.', $prompt->prompt);
    }

    public function testARefinementThatOnlyAsksAQuestionLeavesTheGuideAlone(): void
    {
        $this->plugin->voiceGuide->save("# Tone of voice\n\nOriginal.");
        $this->plugin->voiceState->addMessage('user', 'Make it better.');
        $this->plugin->voiceState->addMessage('assistant', 'Better in what way?');
        $this->plugin->voiceState->addMessage('user', 'Shorter.');

        $this->fake->respond('voice-editor', '<reply>Shorter where?</reply>');

        (new RefineVoiceGuide())->execute(null);

        $this->assertStringContainsString('Original.', $this->plugin->voiceGuide->get());

        // The earlier exchange goes along as the conversation so far.
        $request = $this->fake->prompted('voice-editor')[0];
        $this->assertSame(['user', 'assistant'], array_map(fn($message) => $message->role, $request->history));
        $this->assertStringContainsString('Request: Shorter.', $request->prompt);
    }

    public function testTheScanActionStartsTheJobAndReportsWorking(): void
    {
        $this->signIn();

        $response = $this->action('ghostwriter/voice/scan', ['sections' => ['articles']]);

        $this->assertSame(200, $response['status'], json_encode($response['data']));
        $this->assertSame(VoiceState::WORKING, $response['data']['status']);
        $this->assertSame(['articles'], $this->queued(GenerateVoiceGuide::class)[0]->sections);

        // Not twice at once.
        $this->assertSame(409, $this->action('ghostwriter/voice/scan')['status']);

        // The queue runs it; the screen's next poll sees the guide.
        $this->fake->respond('voice-analyst', "# Tone of voice\n\nWe, to you.");
        $this->runQueue();

        $status = $this->action('ghostwriter/voice/status', method: 'GET')['data'];
        $this->assertSame(VoiceState::IDLE, $status['status']);
        $this->assertTrue($status['exists']);
        $this->assertStringContainsString('We, to you.', $status['document']);
    }

    public function testNothingIsSentWithoutAnApiKey(): void
    {
        $this->unfake();
        $this->plugin->providers->keys['anthropic'] = null;
        $this->signIn();

        $response = $this->action('ghostwriter/voice/scan');

        $this->assertSame(422, $response['status']);
        $this->assertStringContainsString('No API key is set for the anthropic provider.', $response['data']['message']);
        $this->assertSame([], $this->queued(GenerateVoiceGuide::class));
    }

    public function testTheGuideCanBeSavedByHandAndRefinedOnceItExists(): void
    {
        $this->signIn();

        $this->assertSame(422, $this->action('ghostwriter/voice/refine', ['message' => 'Shorter.'])['status']);
        $this->assertSame(422, $this->action('ghostwriter/voice/update', ['document' => '  '])['status']);

        $response = $this->action('ghostwriter/voice/update', ['document' => "# Ours\n\nEdited by hand."]);

        $this->assertSame(200, $response['status'], json_encode($response['data']));
        $this->assertTrue($response['data']['exists']);
        $this->assertStringContainsString('Edited by hand.', $this->plugin->voiceGuide->get());

        $this->assertSame('working', $this->action('ghostwriter/voice/refine', ['message' => 'Shorter.'])['data']['status']);
        $this->assertCount(1, $this->queued(RefineVoiceGuide::class));
    }

    public function testUsersWithoutThePermissionAreTurnedAway(): void
    {
        $this->signIn(permitted: false);

        $this->assertSame(403, $this->action('ghostwriter/dashboard/index', method: 'GET')['status']);
        $this->assertSame(403, $this->action('ghostwriter/voice/scan')['status']);
    }

    private function makeArticle(string $title, string $paragraph, ?string $postDate = null, bool $live = true): void
    {
        $this->makeEntry($this->articles, $title, [
            'summary' => 'A summary line about ' . $title . ' that is long enough to count.',
            'heroImage' => '/uploads/' . strtolower(str_replace(' ', '-', $title)) . '.jpg',
            'body' => '<h2>The Problem</h2><p>' . $paragraph . '</p><blockquote><p>Challenge accepted.</p></blockquote>',
            'pageBuilder' => [
                'new1' => ['type' => 'longForm', 'enabled' => true, 'fields' => ['copy' => 'Property searches and recipe selection, among others.']],
                'new2' => ['type' => 'gallery', 'enabled' => false, 'fields' => ['caption' => 'Switched off and never shown']],
            ],
        ], $live, postDate: $postDate);
    }
}
