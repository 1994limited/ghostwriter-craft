<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\helpers\FileHelper;
use craft\models\Section_SiteSettings;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Preview\PreviewMarkers;
use nineteenninetyfour\ghostwriter\drafts\DraftValues;
use nineteenninetyfour\ghostwriter\tests\support\Sites;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The Preview tab: a draft rendered through the section's own template,
 * with nothing written (page preview design §7.3).
 */
class PreviewTest extends TestCase
{
    use Sites;

    private const DRAFT = "title: What Does a Website Cost?\nsummary: Why quotes vary, and what moves the number.\npageBuilder:\n  - type: hero\n    heading: Quotes, explained\n  - type: longForm\n    content: |\n      Most quotes are for different websites.\n\n      ## Why are the quotes so far apart?\n\n      Because they are for **different** websites.\n\n      ## What moves the number\n\n      Content, mostly.";

    protected function _before(): void
    {
        parent::_before();

        $this->makeArticlesSection();
        $this->makeArticle('One', 'A paragraph about one that says something specific enough to be worth reading twice over.');
        $this->signIn(admin: true);
        // Renders are reused by their values; IDs repeat from test to test.
        Craft::$app->getCache()->flush();
    }

    public function testPreparingAPreviewWritesNothingAndMapsTheBlocks(): void
    {
        $target = $this->newDraft($this->articles);
        $session = $this->session(self::DRAFT, $target);
        $before = $this->written();

        $response = $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $target->id]);

        $this->assertSame(200, $response['status'], json_encode($response['data']));
        $this->assertTrue($response['data']['preview']);
        $this->assertSame($before, $this->written(), 'A preview saves no element, draft or nested entry.');

        // A token URL on the site, at the address the title gives the entry.
        $this->assertStringContainsString('articles/what-does-a-website-cost', $response['data']['url']);
        $this->assertMatchesRegularExpression('/[?&]token=[^&]{32}/', $response['data']['url']);
        $this->assertStringContainsString('x-craft-preview=', $response['data']['url']);

        // The title, the summary, each block, and the long form's sections.
        $map = array_column($response['data']['map'], null, 'key');
        $this->assertSame('title', $map['f1']['path']);
        $this->assertSame('Hero', $map['b1']['label']);
        $this->assertSame(['s1', 's2', 's3'], array_values(array_map(fn($block) => $block['key'], array_filter($map, fn($block) => $block['parent'] === 'b2'))));

        // The draft in the form's own draft is untouched.
        $this->assertNull(Entry::find()->id($target->id)->drafts(null)->status(null)->one()->getFieldValue('summary'));
    }

    public function testAnExistingEntryIsPreviewedWithoutMakingADraftOfIt(): void
    {
        $entry = Entry::find()->section('articles')->one();
        $session = $this->session(self::DRAFT, $entry);
        $before = $this->written();

        $response = $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $entry->id]);

        $this->assertTrue($response['data']['preview'], json_encode($response['data']));
        $this->assertSame($before, $this->written());
        $this->assertSame(0, (int) Entry::find()->draftOf($entry)->provisionalDrafts(null)->status(null)->count(), 'No provisional draft is made, as "Use this draft" would.');
    }

    public function testTheRenderedEntryCarriesMarkersAndApplyNever(): void
    {
        $target = $this->newDraft($this->articles);
        $session = $this->session(self::DRAFT, $target);
        $url = $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $target->id])['data']['url'];

        $element = $this->plugin->previews->element($this->stored($url));

        $this->assertNotNull($element);
        $this->assertSame(['f1'], array_column(PreviewMarkers::decode($element->title), 'key'));
        $this->assertSame('What Does a Website Cost?', PreviewMarkers::stripText($element->title));
        $this->assertSame('articles/what-does-a-website-cost', $element->uri);
        $this->assertTrue($element->previewing);

        $blocks = $element->getFieldValue('pageBuilder')->all();
        $this->assertSame(['hero', 'longForm'], array_map(fn($block) => $block->getType()->handle, $blocks));
        $this->assertNull($blocks[0]->id, 'The blocks are not saved.');
        $this->assertContains('b2.0', array_column(PreviewMarkers::decode((string) $blocks[1]->getFieldValue('content')), 'payload'));

        // "Use this draft" puts the same values in, with no marker anywhere.
        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status']);
        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $this->assertFalse(PreviewMarkers::contains($draft->title));
        $this->assertFalse(PreviewMarkers::contains((string) $draft->getFieldValue('pageBuilder')->status(null)->all()[1]->getFieldValue('content')));
        $this->assertSame(PreviewMarkers::stripText((string) $blocks[1]->getFieldValue('content')), (string) $draft->getFieldValue('pageBuilder')->status(null)->all()[1]->getFieldValue('content'));
    }

    public function testTheSameValuesReuseTheirAddress(): void
    {
        $target = $this->newDraft($this->articles);
        $session = $this->session(self::DRAFT, $target);

        $first = $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $target->id])['data'];
        $second = $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $target->id])['data'];

        $this->assertFalse($first['reused']);
        $this->assertTrue($second['reused']);
        $this->assertSame($first['url'], $second['url']);
        $this->assertSame($first['hash'], $second['hash']);

        $session->draft = str_replace('Content, mostly.', 'Content, and how much of it.', self::DRAFT);
        $this->plugin->sessions->save($session);
        $third = $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $target->id])['data'];

        $this->assertFalse($third['reused']);
        $this->assertNotSame($first['url'], $third['url']);
    }

    public function testTheTokenRoutesOnlyToTheRenderAndHasNoUsageLimit(): void
    {
        $target = $this->newDraft($this->articles);
        $url = $this->action('ghostwriter/preview/prepare', ['id' => $this->session(self::DRAFT, $target)->id, 'elementId' => $target->id])['data']['url'];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $row = (new \craft\db\Query())->from('{{%tokens}}')->where(['token' => $query['token']])->one();

        $this->assertSame('ghostwriter/preview/render', json_decode($row['route'], true)[0]);
        $this->assertNull($row['usageLimit'], 'Craft adds the token to every link; a limit would stop the URL loading twice.');
        $this->assertEqualsWithDelta(time() + 900, strtotime($row['expiryDate'] . ' UTC'), 60);
    }

    public function testASectionWithoutUrlsSaysThereIsNoPage(): void
    {
        $section = Craft::$app->getEntries()->getSectionByHandle('articles');
        $section->setSiteSettings([new Section_SiteSettings(['siteId' => Craft::$app->getSites()->getPrimarySite()->id, 'hasUrls' => false])]);
        Craft::$app->getEntries()->saveSection($section);

        $target = $this->newDraft($this->articles);
        $response = $this->action('ghostwriter/preview/prepare', ['id' => $this->session(self::DRAFT, $target)->id, 'elementId' => $target->id]);

        $this->assertSame(200, $response['status']);
        $this->assertFalse($response['data']['preview']);
        $this->assertSame('no-urls', $response['data']['reason']);
        $this->assertStringContainsString('Blocks', $response['data']['message']);
    }

    public function testThePreviewCanBeSwitchedOff(): void
    {
        $this->plugin->getSettings()->preview = false;
        $target = $this->newDraft($this->articles);

        $response = $this->action('ghostwriter/preview/prepare', ['id' => $this->session(self::DRAFT, $target)->id, 'elementId' => $target->id]);

        $this->assertFalse($response['data']['preview']);
        $this->assertSame('off', $response['data']['reason']);
    }

    public function testSomeoneWhoMayNotEditTheEntryGetsNoPreview(): void
    {
        $target = $this->newDraft($this->articles);
        $session = $this->session(self::DRAFT, $target);
        $this->signIn();

        $this->assertSame(403, $this->action('ghostwriter/preview/prepare', ['id' => $session->id, 'elementId' => $target->id])['status']);
    }

    public function testAPlaceholderIsUsedOnlyWhereOneIsSavedAlready(): void
    {
        $volume = $this->makeVolume();
        $this->plugin->getSettings()->placeholderImages = true;
        $section = $this->makeSection('stories', [$this->makeEntryType('story', [
            $this->makeField(\craft\fields\Assets::class, 'photo', ['sources' => ['volume:' . $volume->uid], 'restrictLocation' => false]),
            $this->makeField(PlainText::class, 'blurb'),
        ])]);

        foreach (['A', 'B', 'C'] as $title) {
            $this->makeEntry($section, $title, ['blurb' => 'Words.', 'photo' => [$this->makeAsset($volume, strtolower($title) . '.jpg')->id]]);
        }

        $target = $this->newDraft($section);
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'stories', [], Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: Pictures\nblurb: A blurb.";
        $this->plugin->sessions->save($session);
        $type = $this->plugin->types->find($session->kind)->forSession($session);
        $assets = (int) \craft\elements\Asset::find()->count();

        $values = (new DraftValues())->for($session, $type, $target, readOnly: true);

        $this->assertArrayNotHasKey('photo', array_filter($values['data']), 'No placeholder is saved for a preview.');
        $this->assertSame($assets, (int) \craft\elements\Asset::find()->count());

        // Once "Use this draft" has made one, the preview shows it too.
        $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $values = (new DraftValues())->for($session, $type, $target, readOnly: true);
        $this->assertNotEmpty($values['data']['photo']);
    }

    public function testCkeditorNestedEntriesRenderUnsaved(): void
    {
        $quote = $this->makeEntryType('pullQuote', [$this->makeField(PlainText::class, 'quote')], hasTitle: false);
        $body = $this->makeField(Ckeditor::class, 'body', ['entryTypes' => [$quote->uid]]);
        $section = $this->makeSection('notes', [$this->makeEntryType('note', [$body])]);
        $target = $this->newDraft($section);
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'notes', [], Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: A note\nbody: |\n  Water goes where it wants.";
        $this->plugin->sessions->save($session);
        $type = $this->plugin->types->find($session->kind)->forSession($session);
        $before = $this->written();

        // A layout that adds a nested entry gives it a preview-only negative ID.
        $result = $this->plugin->previews->prepare($session, $type, $target, Craft::$app->getUser()->getIdentity(), [-101 => ['type' => 'pullQuote', 'fields' => ['quote' => 'A rain garden just agrees with it.'], 'at' => 'body']]);
        $payload = $this->stored($result['url']);
        $this->assertStringContainsString('<craft-entry data-entry-id="-101"></craft-entry>', $payload['values']['body']);

        $map = array_column($result['map'], null, 'key');
        $nested = array_values(array_filter($map, fn($block) => $block['type'] === 'pullQuote'))[0];
        $this->assertSame($map[$nested['parent']]['path'], 'body', 'The nested entry is a child of the field that holds it.');

        $element = $this->plugin->previews->element($payload);
        $html = (string) $element->getFieldValue('body');

        // No partial template: a plain box with the entry's writing and marker.
        $this->assertStringContainsString('ghostwriter-preview-nested', $html);
        $this->assertStringContainsString('A rain garden just agrees with it.', PreviewMarkers::stripText($html));
        $this->assertContains($nested['key'] . '.0', array_column(PreviewMarkers::decode($html), 'payload'));

        // With one, through the entry type's own partial.
        $templates = $this->workspace . '/templates';
        FileHelper::writeToFile($templates . '/_partials/entry/pullQuote.twig', '<blockquote class="pull">{{ entry.quote }}</blockquote>');
        $was = Craft::getAlias('@templates');
        Craft::setAlias('@templates', $templates);

        try {
            $element = $this->plugin->previews->element($payload);
            $html = (string) $element->getFieldValue('body');
        } finally {
            Craft::setAlias('@templates', $was);
        }

        $this->assertStringContainsString('<blockquote class="pull">A rain garden just agrees with it.', PreviewMarkers::stripText($html));
        $this->assertSame($before, $this->written(), 'The nested entry is never saved.');
    }

    public function testTheRenderNeedsAKeyThatHasNotExpired(): void
    {
        Craft::$app->getRequest()->setIsCpRequest(false);
        Craft::$app->getRequest()->setQueryParams(['token' => 'x']);
        $this->plugin->controllerNamespace = 'nineteenninetyfour\\ghostwriter\\controllers';
        $controller = new \nineteenninetyfour\ghostwriter\controllers\PreviewController('preview', $this->plugin);

        $response = (fn() => $this->problem(410, 'This preview has expired.'))->call($controller);

        $this->assertSame(410, $response->getStatusCode());
        $this->assertStringContainsString('data-ghostwriter-preview-error="This preview has expired."', $response->data);
        $this->assertNull($this->plugin->previews->stored(str_repeat('a', 32)));
        $this->assertNull($this->plugin->previews->stored('../not-a-key'));
    }

    public function testThePreviewHeadersBlockThirdPartiesAndAllowAConfiguredHost(): void
    {
        $this->plugin->getSettings()->previewScriptHosts = ['https://cdn.example.com', 'bad host; script-src *'];
        $headers = $this->plugin->previews->headers();

        $this->assertStringStartsWith("script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.example.com;", $headers['Content-Security-Policy']);
        $this->assertStringContainsString("form-action 'none'", $headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('bad host', $headers['Content-Security-Policy']);
        $this->assertSame('no-referrer', $headers['Referrer-Policy']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('private, no-store', $headers['Cache-Control']);
    }

    private function session(string $draft, Entry $target): Session
    {
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'articles', [], Craft::$app->getUser()->getId());
        $session->recordId = (int) $target->getCanonicalId();
        $session->draft = $draft;

        return $this->plugin->sessions->save($session);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(string $url): array
    {
        $tokens = (new \craft\db\Query())->from('{{%tokens}}')->orderBy(['id' => SORT_DESC])->one();
        $key = json_decode($tokens['route'], true)[1]['key'];

        return $this->plugin->previews->stored($key);
    }

    /**
     * What a preview must leave as it was.
     *
     * @return array<string, int>
     */
    private function written(): array
    {
        $count = fn(string $table) => (int) (new \craft\db\Query())->from($table)->count();

        return [
            'elements' => $count('{{%elements}}'),
            'drafts' => $count('{{%drafts}}'),
            'content' => $count('{{%elements_sites}}'),
            'maxId' => (int) (new \craft\db\Query())->from('{{%elements}}')->max('id'),
            'updated' => (string) (new \craft\db\Query())->from('{{%elements}}')->max('dateUpdated'),
        ];
    }
}
