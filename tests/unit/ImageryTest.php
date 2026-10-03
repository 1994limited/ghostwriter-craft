<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use craft\fields\Assets;
use craft\fields\PlainText;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\DomainOptions;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Guides\Guide;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use nineteenninetyfour\ghostwriter\images\ImageSampler;
use nineteenninetyfour\ghostwriter\jobs\GenerateImageryGuide;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * The image style guide: what a section's pictures look like, in words,
 * written from the pictures themselves.
 */
class ImageryTest extends TestCase
{
    private Section $stories;

    private \craft\models\Volume $volume;

    protected function _before(): void
    {
        parent::_before();

        $volume = $this->volume = $this->makeVolume();
        $cover = $this->makeField(Assets::class, 'cover', ['sources' => ['volume:' . $volume->uid], 'maxRelations' => 1]);
        $picture = $this->makeField(Assets::class, 'picture', ['sources' => ['volume:' . $volume->uid], 'maxRelations' => 1]);

        // A picture inside a Matrix, inside a Neo block: as deep as real sites go.
        $media = $this->makeMatrix('media', [$this->makeEntryType('mediaItem', [$picture], hasTitle: false)]);
        $background = $this->makeField(Assets::class, 'background', ['sources' => ['volume:' . $volume->uid], 'maxRelations' => 1]);
        $icon = $this->makeField(Assets::class, 'noteIcon', ['sources' => ['volume:' . $volume->uid], 'maxRelations' => 1]);

        $neo = $this->makeNeo('storyBuilder', [
            ['handle' => 'banner', 'fields' => [$this->makeField(PlainText::class, 'heading'), $media, $background]],
            ['handle' => 'footnote', 'fields' => [$this->makeField(PlainText::class, 'note'), $icon], 'required' => ['noteIcon']],
        ]);

        $this->stories = $this->makeSection('stories', [$this->makeEntryType('story', [$cover, $neo])]);

        foreach (['one', 'two', 'three'] as $i => $slug) {
            $this->makeEntry($this->stories, ucfirst($slug), [
                'cover' => [$this->makeAsset($volume, "{$slug}.png")->id],
                'storyBuilder' => ['blocks' => ['new1' => ['type' => 'banner', 'enabled' => true, 'level' => 1, 'fields' => [
                    'heading' => 'Hi ' . $slug,
                    'media' => ['new1' => ['type' => 'mediaItem', 'enabled' => true, 'fields' => ['picture' => [$this->makeAsset($volume, "banner-{$slug}.png", 600, 900)->id]]]],
                ]]], 'sortOrder' => ['new1']],
            ], postDate: '2026-01-0' . ($i + 1));
        }
    }

    public function testImagesAreFoundWhereverTheySit(): void
    {
        $entry = \craft\elements\Entry::find()->section('stories')->title('One')->one();

        $found = (new ImageSampler())->find($entry);

        $this->assertSame(['Cover', 'Banner: Picture'], array_column($found, 0));
        $this->assertSame(['one.png', 'banner-one.png'], array_map(fn($pair) => $pair[1]->filename, $found));
    }

    public function testTheGuideIsWrittenFromTheSitesImages(): void
    {
        $this->fake->respond('imagery-analyst', '<document>**What they are.** Bright photographs of finished things.</document>');

        (new GenerateImageryGuide(['sections' => ['stories', 'nowhere']]))->execute(null);

        $guide = $this->plugin->domain->guide(Guide::IMAGERY);

        $this->assertSame("# Image style\n\n## Stories\n\n**What they are.** Bright photographs of finished things.\n", $guide->body);
        $this->assertSame('**What they are.** Bright photographs of finished things.', $guide->section('Stories'));
        $this->assertSame('', $guide->section('Elsewhere'));
        $this->assertSame('idle', $this->plugin->domain->guideState(Guide::IMAGERY)->status);
        $this->assertCount(6, $this->plugin->domain->guideState(Guide::IMAGERY)->scanned);

        // The analyst was shown the images, small, labelled by field and entry,
        // taking turns between the two fields.
        $request = $this->fake->prompted('imagery-analyst')[0];

        $this->assertCount(6, $request->images);
        $this->assertSame('image/jpeg', $request->images[0]->mime);
        $this->assertStringContainsString('Section: Stories', $request->prompt);
        $this->assertStringContainsString("1. Cover, on \"Three\"\n2. Banner: Picture, on \"Three\"", $request->prompt);
        $this->assertStringContainsString('Describe the house style', $request->instructions);
    }

    public function testTooFewImagesToCallAStyle(): void
    {
        $this->plugin->getSettings()->imageGuideSamples = 2;

        (new GenerateImageryGuide(['sections' => ['stories']]))->execute(null);

        $this->assertSame('failed', $this->plugin->domain->guideState(Guide::IMAGERY)->status);
        $this->assertStringContainsString('It takes at least three', $this->plugin->domain->guideState(Guide::IMAGERY)->error);
        $this->assertSame([], $this->fake->prompted('imagery-analyst'));
    }

    public function testAPlaceholderMarksEachImageStillToChoose(): void
    {
        $this->signIn(admin: true);

        $target = $this->newDraft($this->stories);
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'stories', [], \Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: A New Story\nstoryBuilder:\n  - type: banner\n    heading: Hello\n  - type: footnote\n    note: With thanks.\n  - type: banner\n    heading: Again";
        $this->plugin->sessions->save($session);

        $notes = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id])['data']['notes'];

        $draft = \craft\elements\Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $placeholder = $draft->getFieldValue('cover')->one();

        // Every story has a cover, so the new one gets a placeholder there.
        $this->assertSame('ghostwriter-image-placeholder.png', $placeholder->filename);

        $blocks = $draft->getFieldValue('storyBuilder')->status(null)->all();

        // Every banner has a picture in its media; none has a background,
        // which is a setting a few might use, so it is left empty.
        foreach ([$blocks[0], $blocks[2]] as $banner) {
            $this->assertSame($placeholder->id, $banner->getFieldValue('media')->one()->getFieldValue('picture')->one()->id);
            $this->assertNull($banner->getFieldValue('background')->one());
        }

        // No footnote has been written before, but its icon is required.
        $this->assertSame($placeholder->id, $blocks[1]->getFieldValue('noteIcon')->one()->id);

        // One placeholder file, reused everywhere. Where they went is kept
        // for "Finish this page", which shows each as a step, so the notice
        // doesn't list them too.
        $this->assertSame(1, (int) \craft\elements\Asset::find()->filename('ghostwriter-image-placeholder.png')->count());
        $this->assertSame([], array_values(array_filter($notes, fn($note) => str_contains($note, 'striped placeholder'))));

        $placed = array_column(array_filter($this->plugin->sessions->find($session->id)->gaps, fn(array $gap) => $gap['kind'] === 'image-placeholder'), 'label');
        $placed = implode(' | ', $placed);

        foreach (['Cover', 'Banner: ', 'Footnote: NoteIcon'] as $expected) {
            $this->assertStringContainsString($expected, $placed);
        }

        $this->assertStringNotContainsString('MediaItem', $placed);
    }

    public function testAnImageChosenWhileWritingIsKeptWhenTheDraftIsUsed(): void
    {
        $this->signIn(admin: true);

        $target = $this->newDraft($this->stories);
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'stories', [], \Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: A New Story";
        $session->addMessage('user', 'Make it warmer.');
        $session->claim(\Craft::$app->getUser()->getId(), new DomainOptions(Format::Craft));
        $this->plugin->sessions->save($session);

        // While the turn runs, a cover is chosen with the image button, and
        // the form saves it to the entry's draft.
        $chosen = $this->makeAsset($this->volume, 'chosen.png');
        $target->setFieldValue('cover', [$chosen->id]);
        $target->setScenario(\craft\base\Element::SCENARIO_ESSENTIALS);
        $this->assertTrue(\Craft::$app->getElements()->saveElement($target));

        // The turn saves only the conversation and its draft, never the entry.
        $this->fake->respond('writer', '<reply>Warmer.</reply><draft>title: A Warmer Story</draft>');
        (new \nineteenninetyfour\ghostwriter\jobs\RunSessionTurn(['sessionId' => $session->id]))->execute(null);
        $this->assertSame('title: A Warmer Story', $this->plugin->sessions->find($session->id)->draft);

        $cover = fn() => \craft\elements\Entry::find()->id($target->id)->drafts(null)->status(null)->one()->getFieldValue('cover')->status(null)->ids();
        $this->assertSame([(int) $chosen->id], array_map('intval', $cover()));

        // Using the draft keeps the chosen cover: no placeholder over it.
        $notes = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id])['data']['notes'];

        $this->assertSame([(int) $chosen->id], array_map('intval', $cover()));
        $this->assertStringNotContainsString('Cover', implode(' ', $notes));
    }

    public function testPlaceholdersCanBeSwitchedOff(): void
    {
        $this->signIn(admin: true);
        $this->plugin->getSettings()->placeholderImages = false;

        $target = $this->newDraft($this->stories);
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'stories', [], \Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: A New Story";
        $this->plugin->sessions->save($session);

        $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);

        $this->assertNull(\craft\elements\Entry::find()->id($target->id)->drafts(null)->status(null)->one()->getFieldValue('cover')->one());
    }

    public function testTheScreenStartsTheJobAndSavesEdits(): void
    {
        $this->signIn();

        $this->assertSame(422, $this->action('ghostwriter/imagery/scan', ['sections' => ['nowhere']])['status']);

        $response = $this->action('ghostwriter/imagery/scan', ['sections' => ['stories']]);

        $this->assertSame('working', $response['data']['status']);
        $this->assertSame(['stories'], $this->queued(GenerateImageryGuide::class)[0]->sections);
        $this->assertSame(409, $this->action('ghostwriter/imagery/scan', ['sections' => ['stories']])['status']);

        $this->assertTrue($this->action('ghostwriter/imagery/update', ['document' => "## Stories\n\nNever people."])['data']['exists']);
        $this->assertSame('Never people.', $this->plugin->domain->guide(Guide::IMAGERY)->section('Stories'));

        $this->assertSame(200, $this->action('ghostwriter/imagery/show', method: 'GET')['status']);
    }
}
