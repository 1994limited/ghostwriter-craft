<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\fields\PlainText;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\PreviewMarkerContract;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's contract for the preview's invisible markers: a value stored by
 * the real apply path ("Use this draft" into CKEditor, through
 * HTMLPurifier), marked, and printed as a template prints it (CKEditor's
 * own rendering, Twig's escaping for plain text), keeps its markers and,
 * without them, is exactly the unmarked page. Craft has no markdown field.
 */
class PreviewMarkerTest extends TestCase
{
    use PreviewMarkerContract;

    private ?Section $section = null;

    protected function markerShapes(): array
    {
        return ['rich', 'plain'];
    }

    protected function storedValue(string $markdown, string $shape): mixed
    {
        $this->signIn(admin: true);
        $handle = $shape === 'rich' ? 'body' : 'summary';
        $target = $this->newDraft($this->section());

        $session = Session::start(Format::Craft, ContentType::GENERIC . 'stories', [], Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: Markers\n{$handle}: |\n" . implode("\n", array_map(fn(string $line) => '  ' . $line, explode("\n", $markdown)));
        $this->plugin->sessions->save($session);

        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));

        $value = Entry::find()->id($target->id)->drafts(null)->status(null)->one()->getFieldValue($handle);

        return trim((string) $value);
    }

    protected function renderValue(mixed $stored, string $shape): string
    {
        if ($shape === 'plain') {
            return Craft::$app->getView()->renderString('{{ value }}', ['value' => (string) $stored]);
        }

        // As a template prints the field: CKEditor parses and renders it.
        $entry = new Entry(['sectionId' => $this->section()->id, 'typeId' => $this->section()->getEntryTypes()[0]->id]);
        $entry->setFieldValue('body', (string) $stored);

        return (string) $entry->getFieldValue('body');
    }

    private function section(): Section
    {
        return $this->section ??= $this->makeSection('stories', [$this->makeEntryType('story', [
            $this->makeField(Ckeditor::class, 'body'),
            $this->makeField(PlainText::class, 'summary', ['multiline' => true]),
        ])]);
    }
}
