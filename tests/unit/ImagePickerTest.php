<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\Section;
use craft\models\Volume;
use GuzzleHttp\Psr7\Response;
use nineteenninetyfour\ghostwriter\ai\Image;
use nineteenninetyfour\ghostwriter\ImageButton;
use nineteenninetyfour\ghostwriter\images\ImagePicker;
use nineteenninetyfour\ghostwriter\images\ImageSlot;
use nineteenninetyfour\ghostwriter\images\LogoCard;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The Ghostwriter button on an image field: find a photograph that suits
 * the block and page, have one made, or set a logo on a ground.
 */
class ImagePickerTest extends TestCase
{
    private Section $stories;

    private Volume $volume;

    private Assets $cover;

    private Assets $docs;

    private Assets $picture;

    protected function _before(): void
    {
        parent::_before();

        Craft::$app->getRequest()->setIsCpRequest(true);
        $this->plugin->getSettings()->openverse = true;
        $this->volume = $this->makeVolume();
        $sources = ['sources' => ['volume:' . $this->volume->uid], 'defaultUploadLocationSource' => 'volume:' . $this->volume->uid];

        $this->cover = $this->makeField(Assets::class, 'cover', $sources + ['maxRelations' => 1]);
        $this->docs = $this->makeField(Assets::class, 'docs', $sources + ['restrictFiles' => true, 'allowedKinds' => ['pdf']]);
        $this->picture = $this->makeField(Assets::class, 'picture', $sources + ['maxRelations' => 1]);

        $feature = $this->makeEntryType('feature', [$this->makeField(PlainText::class, 'heading'), $this->picture], hasTitle: false);
        $blocks = $this->makeMatrix('blocks', [$feature]);

        $this->stories = $this->makeSection('stories', [$this->makeEntryType('story', [$this->cover, $this->docs, $this->makeField(PlainText::class, 'intro'), $blocks])]);

        foreach (['One', 'Two'] as $i => $title) {
            $this->makeEntry($this->stories, $title, [
                'cover' => [$this->makeAsset($this->volume, strtolower($title) . '.png', 900, 600)->id],
                'blocks' => ['entries' => ['new1' => ['type' => 'feature', 'enabled' => true, 'fields' => [
                    'heading' => "About {$title}",
                    'picture' => [$this->makeAsset($this->volume, 'tall-' . strtolower($title) . '.png', 600, 900)->id],
                ]]], 'sortOrder' => ['new1']],
            ], postDate: '2026-01-0' . ($i + 1));
        }
    }

    public function testTheButtonIsOfferedOnImageFieldsOfEntriesGhostwriterWritesFor(): void
    {
        $this->signIn();
        $draft = $this->newDraft($this->stories);

        $html = ImageButton::htmlFor($this->cover, $draft, false);

        $this->assertStringContainsString('data-ghostwriter-image', $html);
        $this->assertStringContainsString('&quot;label&quot;:&quot;Cover&quot;', $html);

        // Not on a field kept to PDFs, nor in an element index cell.
        $this->assertSame('', ImageButton::htmlFor($this->docs, $draft, false));
        $this->assertSame('', ImageButton::htmlFor($this->cover, $draft, true));

        // Not in a section Ghostwriter does not write for.
        $this->plugin->getSettings()->sections = ['elsewhere'];
        $this->assertSame('', ImageButton::htmlFor($this->cover, $draft, false));
    }

    public function testTheButtonIsNotOfferedWithoutThePermission(): void
    {
        $this->signIn(permitted: false);

        $this->assertSame('', ImageButton::htmlFor($this->cover, $this->newDraft($this->stories), false));
    }

    public function testAFieldInABlockIsReadWithTheBlockFirst(): void
    {
        $this->signIn();
        $draft = $this->draftWithBlock();
        $block = $draft->getFieldValue('blocks')->status(null)->one();

        $slot = ImageSlot::for($this->picture, $block, Craft::$app->getUser()->getIdentity());

        $this->assertSame('Feature: Picture', $slot->label());
        $this->assertSame('Mended bowls', $slot->blockText());
        $this->assertStringContainsString('# Repair over replace', $slot->pageText());
        $this->assertStringContainsString('Why we mend things.', $slot->pageText());
        $this->assertSame($draft->id, $slot->root->id);

        // The same place on the other stories: their tall feature pictures.
        $this->assertEqualsCanonicalizing(['tall-one.png', 'tall-two.png'], array_map(fn(Asset $asset) => $asset->filename, $slot->references()));
        $this->assertSame('portrait', $slot->shape());
    }

    public function testSearchesAreCleanedUp(): void
    {
        $this->assertSame(['mended bowls', 'gold repair', 'kintsugi'], ImagePicker::terms("Mended  bowls; gold, repair!\n kintsugi; a fourth"));
        $this->assertSame([], ImagePicker::terms(' ; '));
    }

    public function testPhotographsAreFoundForTheBlockAndTheChosenOneIsKept(): void
    {
        $this->signIn();
        $draft = $this->draftWithBlock();
        $block = $draft->getFieldValue('blocks')->status(null)->one();

        $this->fake->respond('photo-researcher', 'mended bowls; gold repair; old workshop');
        $this->fake->respond('photo-picker', '5, 2');

        // Two results for each search, then a thumbnail of each.
        foreach (['a', 'b', 'c'] as $term) {
            $this->http->append(new Response(200, [], json_encode(['results' => [
                ['id' => "{$term}1", 'thumbnail' => "https://example.com/{$term}1.jpg", 'creator' => 'Ann', 'license' => 'cc0', 'foreign_landing_url' => 'https://example.com'],
                ['id' => "{$term}2", 'thumbnail' => "https://example.com/{$term}2.jpg", 'creator' => 'Bo', 'license' => 'pdm'],
            ]])));
        }

        foreach (range(1, 6) as $i) {
            $this->http->append(new Response(200, ['Content-Type' => 'image/png'], $this->png()));
        }

        $started = $this->action('ghostwriter/images/start', ['fieldId' => $this->picture->id, 'elementId' => $block->id, 'siteId' => $block->siteId, 'mode' => 'find', 'words' => '']);

        $this->assertSame(200, $started['status']);
        $this->assertSame('working', $started['data']['status']);

        $this->runQueue();

        $found = $this->action('ghostwriter/images/status', ['id' => $started['data']['id']], 'GET')['data'];

        $this->assertSame('ready', $found['status']);
        $this->assertSame(['mended bowls', 'gold repair', 'old workshop'], $found['terms']);

        // The two the picker chose come first; the rest follow.
        $this->assertSame(['c1', 'a2', 'a1', 'b1', 'b2', 'c2'], array_column($found['options'], 'id'));
        $this->assertSame([true, true], array_column(array_slice($found['options'], 0, 2), 'picked'));

        // Searched for tall pictures, as the feature pictures are, Openverse
        // for free work only. The researcher read the block and the page.
        parse_str($this->sent[0]['request']->getUri()->getQuery(), $query);
        $this->assertSame(['mended bowls', 'tall', 'cc0,pdm'], [$query['q'], $query['aspect_ratio'], $query['license']]);
        $this->assertStringContainsString("Words in that part of the page:\n\nMended bowls", $this->fake->prompted('photo-researcher')[0]->prompt);
        $this->assertCount(2 + 6, $this->fake->prompted('photo-picker')[0]->images);

        // The photograph is looked up again by its ID and downloaded.
        $this->http->append(
            new Response(200, [], json_encode(['id' => 'c1', 'url' => 'https://example.com/full.png', 'creator' => 'Ann', 'license' => 'cc0', 'foreign_landing_url' => 'https://example.com/c1'])),
            new Response(200, ['Content-Type' => 'image/png'], $this->png()),
        );

        $used = $this->action('ghostwriter/images/use', ['id' => $found['id'], 'source' => 'openverse', 'photo' => 'c1', 'term' => 'old workshop']);

        $this->assertSame(200, $used['status']);

        $asset = Asset::find()->id($used['data']['assetId'])->one();

        $this->assertSame('Old workshop', $asset->title);
        $this->assertSame($this->volume->id, $asset->volumeId);
        $this->assertStringStartsWith('old-workshop-', $asset->filename);
    }

    public function testAPhotographFromElsewhereIsRefused(): void
    {
        $this->signIn();
        $draft = $this->newDraft($this->stories);

        $this->fake->respond('photo-researcher', 'bowls');
        $this->http->append(new Response(200, [], json_encode(['results' => []])), new Response(200, [], json_encode(['results' => []])));

        $started = $this->action('ghostwriter/images/start', ['fieldId' => $this->cover->id, 'elementId' => $draft->id, 'siteId' => $draft->siteId, 'mode' => 'find', 'words' => 'bowls']);
        $this->runQueue();

        $this->assertSame('Nothing was found for those searches. Try other words.', $this->action('ghostwriter/images/status', ['id' => $started['data']['id']], 'GET')['data']['error']);

        // A library that is not switched on is not fetched from.
        $used = $this->action('ghostwriter/images/use', ['id' => $started['data']['id'], 'source' => 'unsplash', 'photo' => 'x1']);

        $this->assertSame(422, $used['status']);
        $this->assertSame('That photograph could not be found.', $used['data']['message']);
    }

    public function testAPictureIsMadeInTheStyleOfThoseAlreadyThere(): void
    {
        $this->signIn();
        $draft = $this->newDraft($this->stories);
        $this->fake->respondWithImage(new Image($this->png(), 'image/png'));

        $started = $this->action('ghostwriter/images/start', ['fieldId' => $this->cover->id, 'elementId' => $draft->id, 'siteId' => $draft->siteId, 'mode' => 'make', 'direction' => 'A mended bowl on a bench']);
        $this->runQueue();

        $made = $this->action('ghostwriter/images/status', ['id' => $started['data']['id']], 'GET')['data'];

        $this->assertSame('ready', $made['status']);
        $this->assertStringContainsString('images/preview', $made['preview']);

        $request = $this->fake->imageRequests[0];

        $this->assertCount(2, $request->references);
        $this->assertSame('landscape', $request->shape);
        $this->assertStringContainsString('Make the "Cover" image', $request->prompt);
        $this->assertStringContainsString('A mended bowl on a bench', $request->prompt);

        $used = $this->action('ghostwriter/images/use', ['id' => $made['id']]);

        $this->assertSame('A mended bowl on a bench', Asset::find()->id($used['data']['assetId'])->one()->title);
    }

    public function testARequestIsOnlyForWhoeverMadeIt(): void
    {
        $this->signIn();
        $draft = $this->newDraft($this->stories);
        $this->fake->respondWithImage(new Image($this->png(), 'image/png'));

        $started = $this->action('ghostwriter/images/start', ['fieldId' => $this->cover->id, 'elementId' => $draft->id, 'siteId' => $draft->siteId, 'mode' => 'make']);

        $this->signIn(extra: ['saveEntries:' . $this->stories->uid]);

        $this->assertSame(404, $this->action('ghostwriter/images/status', ['id' => $started['data']['id']], 'GET')['status']);
    }

    public function testAFieldThatTakesNoImagesIsRefused(): void
    {
        $this->signIn();
        $draft = $this->newDraft($this->stories);

        $result = $this->action('ghostwriter/images/start', ['fieldId' => $this->docs->id, 'elementId' => $draft->id, 'siteId' => $draft->siteId, 'mode' => 'find']);

        $this->assertSame(404, $result['status']);

        // Nor does a placeholder go there.
        $docs = array_values(array_filter((new SchemaReader())->read($this->stories->getEntryTypes()[0]), fn(array $spec) => $spec['handle'] === 'docs'))[0];
        $this->assertFalse($docs['images']);
    }

    public function testALogoIsSetOnAGround(): void
    {
        if (!LogoCard::available()) {
            $this->markTestSkipped('Imagick is not installed.');
        }

        $logo = imagecreatetruecolor(200, 100);
        imagesavealpha($logo, true);
        imagefill($logo, 0, 0, imagecolorallocatealpha($logo, 0, 0, 0, 127));
        imagefilledrectangle($logo, 50, 25, 150, 75, imagecolorallocate($logo, 0xff, 0x2d, 0x20));
        ob_start();
        imagepng($logo);

        $card = (new LogoCard())->compose((string) ob_get_clean(), 800, 500);
        $size = getimagesizefromstring($card['content']);

        $this->assertSame([800, 500, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
        $this->assertSame('#ff2d20', $card['colour']);
    }

    private function draftWithBlock(): Entry
    {
        $draft = $this->newDraft($this->stories);
        $draft->title = 'Repair over replace';
        $draft->setFieldValue('intro', 'Why we mend things.');
        $draft->setFieldValue('blocks', ['entries' => ['new1' => ['type' => 'feature', 'enabled' => true, 'fields' => ['heading' => 'Mended bowls']]], 'sortOrder' => ['new1']]);
        $draft->setScenario(\craft\base\Element::SCENARIO_ESSENTIALS);
        Craft::$app->getElements()->saveElement($draft);

        return $draft;
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
