<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Codeception\Test\Unit;
use nineteenninetyfour\ghostwriter\ai\TaggedResponse;

class TaggedResponseTest extends Unit
{
    public function testItSeparatesTheReplyFromTheDocument(): void
    {
        $response = TaggedResponse::parse("<reply>\nHere you go.\n</reply>\n<draft>\n---\ntitle: A\n---\n\n## One\n</draft>", 'draft');

        $this->assertSame('Here you go.', $response->reply);
        $this->assertSame("---\ntitle: A\n---\n\n## One", $response->document);
    }

    public function testAReplyWithoutADocumentLeavesTheDocumentNull(): void
    {
        $response = TaggedResponse::parse('<reply>1. What did it cost?</reply>', 'draft');

        $this->assertSame('1. What did it cost?', $response->reply);
        $this->assertNull($response->document);
    }

    public function testADocumentCutOffBeforeItsClosingTagIsStillKept(): void
    {
        $response = TaggedResponse::parse("<reply>Draft below.</reply>\n<draft>\n---\ntitle: A\n---\n\n## One\n\nIt stops he", 'draft');

        $this->assertStringEndsWith('It stops he', $response->document);
    }

    public function testTextOutsideTheFormatBecomesTheReply(): void
    {
        $response = TaggedResponse::parse('I could not follow the format, sorry.', 'draft');

        $this->assertSame('I could not follow the format, sorry.', $response->reply);
        $this->assertNull($response->document);
    }

    public function testTheImagesBlockIsKeptApartFromTheReply(): void
    {
        $response = TaggedResponse::parse("Here it is.\n<draft>title: A</draft>\n<images>\ncover | find | harbour\n</images>", 'draft');

        $this->assertSame('Here it is.', $response->reply);
        $this->assertSame('cover | find | harbour', $response->images);
    }
}
