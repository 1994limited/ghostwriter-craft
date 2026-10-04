<?php

namespace nineteenninetyfour\ghostwriter\tests\unit\seo;

use Craft;
use craft\ckeditor\Field as Ckeditor;
use craft\models\Section;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Format;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Kinds\ContentType;
use NineteenNinetyFour\Ghostwriter\Core\Domain\Sessions\Session;
use NineteenNinetyFour\Ghostwriter\Core\Seo\Outline;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfile;
use NineteenNinetyFour\Ghostwriter\Core\Seo\RenderProfiles;
use NineteenNinetyFour\Ghostwriter\Core\Seo\SeoPass;
use NineteenNinetyFour\Ghostwriter\Core\Tests\Contracts\RenderProfileContract;
use nineteenninetyfour\ghostwriter\seo\HeadingProfiles;
use nineteenninetyfour\ghostwriter\seo\StateRenderProfiles;
use nineteenninetyfour\ghostwriter\tests\support\TestCase;

/**
 * Core's RenderProfileContract on the plugin's state table, recorded as
 * PreviewController::actionOutline() records them; and the action itself.
 */
class RenderProfileTest extends TestCase
{
    use RenderProfileContract;

    protected function profiles(): RenderProfiles
    {
        return HeadingProfiles::store();
    }

    protected function record(string $key, array $outline): RenderProfile
    {
        return (new SeoPass())->observe($this->profiles(), $key, Outline::fromArray($outline), 'Pages')[0];
    }

    protected function seededOutline(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/seeded-outline.json'), true);
    }

    public function testThePreviewPostsItsOutlineAndTwoAgreeingRendersChangeTheProfile(): void
    {
        $this->signIn(admin: true);
        $section = $this->makeSection('notes', [$this->makeEntryType('note', [$this->makeField(Ckeditor::class, 'body')])]);
        $session = Session::start(Format::Craft, ContentType::GENERIC . 'notes', [], Craft::$app->getUser()->getId());
        $session->draft = "title: A note\nbody: |\n  Text.";
        $this->plugin->sessions->save($session);
        $none = [['level' => 2, 'text' => 'Visits', 'field' => 'body', 'unit' => null, 'inContent' => true]];
        $key = StateRenderProfiles::key('notes', 'note', Craft::$app->getSites()->getPrimarySite()->handle);

        $first = $this->action('ghostwriter/preview/outline', ['id' => $session->id, 'outline' => $this->seededOutline()]);
        $this->assertSame(200, $first['status'], json_encode($first['data']));
        $this->assertSame(['changed' => false, 'h1' => 'title', 'renders' => 1], $first['data']);

        $this->assertFalse($this->action('ghostwriter/preview/outline', ['id' => $session->id, 'outline' => $none])['data']['changed']);
        $this->assertSame(['changed' => true, 'h1' => 'none', 'renders' => 2], $this->action('ghostwriter/preview/outline', ['id' => $session->id, 'outline' => $none])['data']);
        $this->assertSame('no-h1', HeadingProfiles::store()->get($key)?->problem());
        $this->assertStringContainsString('Pages in Notes print no main heading (H1)', (string) HeadingProfiles::store()->get($key)?->note());
        $this->assertSame(422, $this->action('ghostwriter/preview/outline', ['id' => $session->id, 'outline' => 'nonsense'])['status']);
    }
}
