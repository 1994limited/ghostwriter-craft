<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\seo;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\elements\Entry;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Field;
use NineteenNinetyFour\Ghostwriter\Core\Schema\HeadingLevels;
use NineteenNinetyFour\Ghostwriter\Core\Schema\Schema;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\HeadingLevelsContract;
use nineteenninetyfour\ghostwriter\layouts\SchemaReader;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's HeadingLevelsContract on CKEditor: the reader records the levels
 * a field's toolbar offers, and the fitted markdown goes through "Use this
 * draft" (core's EntryBuilder, HTMLPurifier, the provisional draft) with
 * only those levels.
 */
class HeadingLevelsTest extends TestCase
{
    use HeadingLevelsContract;

    private ?Section $section = null;

    protected function twoLevelField(): Field
    {
        return $this->schema()->field('body');
    }

    protected function noHeadingField(): Field
    {
        return $this->schema()->field('note');
    }

    protected function stored(string $markdown, Field $field): string
    {
        $this->signIn(admin: true);
        $target = $this->newDraft($this->section());

        $session = Session::start(Format::Craft, ContentType::GENERIC . 'visits', [], Craft::$app->getUser()->getId());
        $session->recordId = $target->id;
        $session->draft = "title: Headings\n{$field->handle}: |\n" . implode("\n", array_map(fn(string $line) => '  ' . $line, explode("\n", $markdown)));
        $this->plugin->sessions->save($session);

        $applied = $this->action('ghostwriter/sessions/apply', ['id' => $session->id, 'elementId' => $target->id]);
        $this->assertSame(200, $applied['status'], json_encode($applied['data']));

        $value = (string) Entry::find()->id($target->id)->drafts(null)->status(null)->one()->getFieldValue($field->handle);

        return (string) $this->plugin->layouts->core->richText->toMarkdown($value, $field);
    }

    public function testACkeditorFieldWithAllItsLevelsHasAll(): void
    {
        $this->assertSame(HeadingLevels::ALL, HeadingLevels::allowed($this->schema()->field('summary')));
    }

    private function schema(): Schema
    {
        return (new SchemaReader())->schema($this->section()->getEntryTypes()[0]);
    }

    private function section(): Section
    {
        return $this->section ??= $this->makeSection('visits', [$this->makeEntryType('visit', [
            $this->makeField(Ckeditor::class, 'body', ['toolbar' => ['heading', '|', 'bold', 'italic', 'link'], 'headingLevels' => [2, 3]]),
            $this->makeField(Ckeditor::class, 'note', ['toolbar' => ['bold', 'italic'], 'headingLevels' => [2, 3]]),
            $this->makeField(Ckeditor::class, 'summary', ['toolbar' => ['heading', 'bold']]),
        ])]);
    }
}
