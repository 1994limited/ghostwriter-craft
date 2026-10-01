<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use Codeception\Test\Unit;
use nineteenninetyfour\ghostwriter\ai\LenientYaml;
use Symfony\Component\Yaml\Exception\ParseException;

/**
 * YAML written by a model: prose with apostrophes, quotation marks and
 * colons in it, which strict YAML reads as syntax.
 */
class LenientYamlTest extends Unit
{
    public function testProseThatBreaksYamlQuotingIsStillRead(): void
    {
        $data = LenientYaml::parse(implode("\n", [
            "title: 'How to Brief a Web Agency'",
            "intro: 'Send us something even if it's rough. Here's what to put in it:'",
            'summary: What to send: a page is plenty',
            'quote: "She said "go" and we went"',
            'blocks:',
            '  - type: text',
            "    heading: 'It's fine'",
            '    body: |',
            "      It's a block: nothing here is touched.",
            "      'Quoted' too.",
            "  - 'Don't skip this'",
        ]));

        $this->assertSame('How to Brief a Web Agency', $data['title']);
        $this->assertSame("Send us something even if it's rough. Here's what to put in it:", $data['intro']);
        $this->assertSame('What to send: a page is plenty', $data['summary']);
        $this->assertSame('She said "go" and we went', $data['quote']);
        $this->assertSame("It's fine", $data['blocks'][0]['heading']);
        $this->assertSame("It's a block: nothing here is touched.\n'Quoted' too.\n", $data['blocks'][0]['body']);
        $this->assertSame("Don't skip this", $data['blocks'][1]);
    }

    public function testAValueWrappedOverLinesIsStillRead(): void
    {
        $data = LenientYaml::parse(implode("\n", [
            'title: Who Owns What',
            'blocks:',
            '  - type: text',
            '    why: "Launch" and "Support" both answer it: whose name is on the code',
            '      and who holds the keys after launch.',
            '    notes: Set it out plainly: code, hosting, support',
            '      and how that sits alongside the relationship.',
            '    tint: blue',
        ]));

        $this->assertSame('"Launch" and "Support" both answer it: whose name is on the code and who holds the keys after launch.', $data['blocks'][0]['why']);
        $this->assertSame('Set it out plainly: code, hosting, support and how that sits alongside the relationship.', $data['blocks'][0]['notes']);
        $this->assertSame('blue', $data['blocks'][0]['tint']);
    }

    public function testValidYamlIsReadAsItStands(): void
    {
        $this->assertSame(['a' => ['b' => [1, 2]], 'c' => 'd: e'], LenientYaml::parse("a:\n  b: [1, 2]\nc: 'd: e'"));
    }

    public function testTextThatCannotBeRepairedReportsTheOriginalComplaint(): void
    {
        $this->expectException(ParseException::class);

        LenientYaml::parse("title: A\n  bad: [indent");
    }
}
