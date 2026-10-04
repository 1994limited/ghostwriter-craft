<?php

namespace nineteenninetyfour\ghostwriter\tests\unit;

use NineteenNinetyFour\Ghostwriter\Core\Gaps\MarkerResolver;
use nineteenninetyfour\ghostwriter\drafts\DraftValues;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * A link chosen from its chip for a field the draft doesn't hold: the
 * build's notes and the house style's places stop saying it is still to
 * choose.
 */
class ChosenLinkNotesTest extends TestCase
{
    public function testNotesLoseTheLinksSinceChosen(): void
    {
        $chosen = MarkerResolver::chosenLinks(MarkerResolver::chooseLink([], 'button link', '{entry:1@1:url||/contact}', '/contact'));
        $notes = [
            'Still to choose by hand: Hero: Image; Hero: Button link.',
            'Still to set by hand, as it differs from page to page: Hero (link still to choose).',
            'Ghostwriter drafts start unpublished.',
        ];
        $built = ['hero' => ['buttonLink' => ['type' => 'url', 'value' => '/contact']]];

        $this->assertSame(['Still to choose by hand: Hero: Image.', 'Ghostwriter drafts start unpublished.'], DraftValues::withoutChosen($notes, $chosen, $built));
        $this->assertSame(['Hero: Image'], DraftValues::withoutChosen(['Hero: Image', 'Hero (link still to choose)'], $chosen, $built, places: true));

        // A link still to choose elsewhere keeps the house style's place.
        $this->assertSame(['Hero (link still to choose)'], DraftValues::withoutChosen(['Hero (link still to choose)'], $chosen, ['cta' => ['value' => 'https://example.com/#gw-link:link']], places: true));
    }
}
