<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\gaps;

use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Markers;
use NineteenNinetyFour\Ghostwriter\Core\Layout\HtmlDialect;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\MarkerRoundTripContract;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the markers: a fact to add and a link to choose,
 * put into an entry by the real apply path ("Use this draft", the Applier
 * writing a Craft draft through CKEditor and HTMLPurifier), come back
 * unchanged. Craft has no markdown field, so that shape is left out.
 */
class MarkerRoundTripTest extends TestCase
{
    use MarkerRoundTripContract;

    private ?Section $section = null;

    protected function markerShapes(): array
    {
        return ['rich', 'plain'];
    }

    protected function roundTripMarkers(string $markdown, string $shape): string
    {
        $this->signIn(admin: true);
        $section = $this->section();
        $handle = $shape === 'rich' ? 'body' : 'summary';

        $target = $this->newDraft($section);
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'stories', [], \Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: Markers\n{$handle}: |\n" . implode("\n", array_map(fn(string $line) => '  ' . $line, explode("\n", $markdown)));
        $this->plugin->sessions->save($session);

        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));

        $draft = Entry::find()->id($target->id)->drafts(null)->status(null)->one();
        $value = $draft->getFieldValue($handle);

        if ($shape !== 'rich') {
            return (string) $value;
        }

        $field = (new SchemaReader())->schema($draft->getType())->field('body');

        return (string) (new HtmlDialect())->toMarkdown((string) $value, $field);
    }

    public function testTheSessionKeepsWhatTheDraftLeftAndTheGuideIsToOpen(): void
    {
        $this->signIn(admin: true);
        $target = $this->newDraft($this->section());
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'stories', [], \Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: Markers\nbody: Tickets cost [[ask: adult ticket price]].";
        $this->plugin->sessions->save($session);

        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id])['data'];

        $this->assertTrue($applied['finish'], 'The guide opens after the reload.');
        $this->assertTrue(Markers::has((string) Entry::find()->id($target->id)->drafts(null)->status(null)->one()->getFieldValue('body')));

        $this->plugin->getSettings()->finishOpenAfterDraft = false;
        $this->assertFalse($this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id])['data']['finish']);
    }

    private function section(): Section
    {
        return $this->section ??= $this->makeSection('stories', [$this->makeEntryType('story', [
            $this->makeField(Ckeditor::class, 'body'),
            $this->makeField(PlainText::class, 'summary', ['multiline' => true]),
        ])]);
    }
}
