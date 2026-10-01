<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Codeception\Test\Unit;
use nineteenninetyfour\ghostwriter\drafts\HtmlToMarkdown;

/**
 * CKEditor and Redactor HTML, read back as the markdown a writer types.
 */
class HtmlToMarkdownTest extends Unit
{
    public function testRichTextBecomesMarkdown(): void
    {
        $html = '<h2>Heading</h2><p>A <strong>bold</strong> and <em>italic</em> <a href="https://example.com">link</a>.</p>'
            . '<ul><li>one</li><li>two<ul><li>nested</li></ul></li></ul><ol><li>first</li><li>second</li></ol>'
            . '<blockquote><p>Quoted.</p></blockquote><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table>';

        $this->assertSame(
            "## Heading\n\nA **bold** and *italic* [link](https://example.com).\n\n- one\n- two\n  - nested\n\n1. first\n2. second\n\n> Quoted.\n\n| A | B |\n| --- | --- |\n| 1 | 2 |",
            (new HtmlToMarkdown())->convert($html),
        );
    }

    public function testFurnitureIsDroppedAndFormattingWhitespaceIgnored(): void
    {
        $html = "<p>\n    Before   the\n    picture.<br>New line.\n</p>\n<figure class=\"image\"><img src=\"/a.jpg\" alt=\"\"><figcaption>Caption</figcaption></figure>\n<craft-entry data-entry-id=\"4\"></craft-entry><p>After.</p>";

        $this->assertSame("Before the picture.  \nNew line.\n\nAfter.", (new HtmlToMarkdown())->convert($html));
    }

    public function testTextOutsideAnyParagraphIsKept(): void
    {
        $this->assertSame('Loose words, **some bold**.', (new HtmlToMarkdown())->convert('Loose words, <b>some bold</b>.'));
        $this->assertSame('', (new HtmlToMarkdown())->convert('  '));
    }
}
