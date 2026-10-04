<?php

namespace nineteenninetyfour\ghostwriter\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\models\Section;
use DateTimeImmutable;
use NineteenNinetyFour\Ghostwriter\Core\Gaps\Message;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\Priority;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitReason;
use NineteenNinetyFour\Ghostwriter\Core\Revisit\RevisitRow;
use NineteenNinetyFour\Ghostwriter\Core\Suggest\EntryRef;
use nineteenninetyfour\ghostwriter\Plugin;
use nineteenninetyfour\ghostwriter\suggest\Revisit;
use nineteenninetyfour\ghostwriter\suggest\SuggestEdits;
use nineteenninetyfour\ghostwriter\web\assets\cp\GhostwriterAsset;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Content to revisit: live pages ranked by free checks (dates, links, alt
 * text, empty fields and age), with their reasons and a Review button
 * that opens the page with Suggest edits ready to run. No model, ever;
 * the list is kept current on save, by the daily command and, with no
 * cron, by Craft's garbage collection or opening this page.
 */
class RevisitController extends Controller
{
    public const PER_PAGE = 25;

    /** The tiles, and the reason kinds each filters the list to. */
    public const TILES = [
        'worth-a-look' => [],
        'past-year' => ['past-year', 'relative-time', 'closing-date'],
        'broken-link' => ['broken-link', 'external-link'],
        'missing-alt' => ['missing-alt'],
    ];

    public function actionShow(): Response
    {
        $plugin = Plugin::getInstance();
        $site = Cp::requestedSite() ?? Craft::$app->getSites()->getPrimarySite();
        $now = new DateTimeImmutable();
        $tile = array_key_exists((string) $this->request->getQueryParam('show'), self::TILES) ? (string) $this->request->getQueryParam('show') : null;
        $visible = $this->visibleSections();
        $section = $this->request->getQueryParam('section');
        $section = is_string($section) && isset($visible[$section]) ? $section : null;
        $kinds = $tile !== null ? self::TILES[$tile] : [];
        $page = max(1, (int) $this->request->getQueryParam('page', 1));
        $last = $plugin->revisit->lastRun((int) $site->id);
        $store = $plugin->revisitStore;

        // No cron: the daily pass, queued, once it's over a day old (and the first time).
        $plugin->revisit->queueIfDue($now);

        $listed = array_values(array_filter(
            $store->top((int) $site->id, $section, 2000, 0, $kinds, $now),
            fn(RevisitRow $row) => isset($visible[$row->entry->group]) && ($tile !== 'worth-a-look' || $row->score >= Priority::WORTH_A_LOOK),
        ));
        $stats = $store->stats((int) $site->id, $now);
        $url = fn(array $params) => UrlHelper::cpUrl('ghostwriter/revisit', array_filter($params + ['site' => Craft::$app->getIsMultiSite() ? $site->handle : null], fn($value) => $value !== null && $value !== ''));

        $this->view->registerAssetBundle(GhostwriterAsset::class);
        $this->view->registerTranslations('ghostwriter', ['{title} is snoozed for 90 days.', 'Something went wrong.']);

        return $this->renderTemplate('ghostwriter/revisit', [
            'tiles' => array_map(fn(string $key) => [
                'key' => $key,
                'count' => $key === 'worth-a-look' ? (int) ($stats['worth-a-look'] ?? 0) : array_sum(array_map(fn(string $kind) => (int) ($stats[$kind] ?? 0), self::TILES[$key])),
                'label' => SuggestEdits::text(new Message('revisit.tile.' . $key)),
                'url' => $url(['show' => $key === $tile ? null : $key, 'section' => $section]),
                'current' => $key === $tile,
            ], array_keys(self::TILES)),
            'tile' => $tile,
            'section' => $section,
            'sections' => array_map(fn(Section $found) => [
                'handle' => $found->handle,
                'name' => Craft::t('site', $found->name),
                'url' => $url(['show' => $tile, 'section' => $found->handle]),
            ], array_values($visible)),
            'allUrl' => $url(['show' => $tile]),
            'rows' => array_map(fn(RevisitRow $row) => $this->row($row, $visible), array_slice($listed, ($page - 1) * self::PER_PAGE, self::PER_PAGE)),
            'page' => $page,
            'pages' => max(1, (int) ceil(count($listed) / self::PER_PAGE)),
            'prevUrl' => $page > 1 ? $url(['show' => $tile, 'section' => $section, 'page' => $page > 2 ? $page - 1 : null]) : null,
            'nextUrl' => $page * self::PER_PAGE < count($listed) ? $url(['show' => $tile, 'section' => $section, 'page' => $page + 1]) : null,
            'total' => count($listed),
            'empty' => SuggestEdits::text(new Message('revisit.empty')),
            'reading' => $last === null,
            'lastRun' => $plugin->revisit->lastRunText((int) $site->id),
            'stale' => $last === null || $last <= $now->modify('-' . Revisit::DAILY . ' seconds'),
            'cron' => Revisit::CRON,
            'externalLinks' => $plugin->getSettings()->checksExternalLinks(),
            'settingsUrl' => Craft::$app->getUser()->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges ? UrlHelper::cpUrl('settings/plugins/ghostwriter') . '#suggest-edits' : null,
            'site' => $site,
            'sites' => Craft::$app->getIsMultiSite() ? array_map(fn($other) => ['name' => Craft::t('site', $other->getName()), 'url' => UrlHelper::cpUrl('ghostwriter/revisit', ['site' => $other->handle]), 'current' => $other->id === $site->id], Craft::$app->getSites()->getEditableSites()) : [],
        ]);
    }

    /** Snooze for 90 days: off the list for the site, for everyone. */
    public function actionSnooze(): Response
    {
        $this->requirePostRequest();

        $key = (string) $this->request->getRequiredBodyParam('key');

        if (preg_match('/^([^:]+):(\d+)@(\d+)$/', $key, $m) !== 1) {
            throw new NotFoundHttpException();
        }

        $ref = new EntryRef($m[1], (int) $m[2], (int) $m[3]);
        $store = Plugin::getInstance()->revisitStore;
        $row = $store->get($ref);
        $entry = Entry::find()->id((int) $ref->id)->siteId((int) $ref->site)->status(null)->one();

        if ($row === null || $entry === null) {
            throw new NotFoundHttpException();
        }

        if (!Craft::$app->getElements()->canSave($entry, Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException();
        }

        $store->put($row->snooze(new DateTimeImmutable()));

        return $this->asJson(['snoozed' => true]);
    }

    /**
     * @param array<string, Section> $sections
     * @return array<string, mixed>
     */
    private function row(RevisitRow $row, array $sections): array
    {
        $reasons = $row->reasons;
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($reasons, fn(RevisitReason $a, RevisitReason $b) => $order[$a->severity()] <=> $order[$b->severity()]);
        $updated = $row->updatedAt ? new DateTimeImmutable($row->updatedAt) : null;

        return [
            'key' => $row->entry->key(),
            'title' => $row->title,
            'section' => isset($sections[$row->entry->group]) ? Craft::t('site', $sections[$row->entry->group]->name) : $row->entry->group,
            'updated' => $updated ? Craft::$app->getFormatter()->asDate($updated->getTimestamp(), 'MMM y') : null,
            'updatedIso' => $row->updatedAt,
            'reasons' => array_map(fn(RevisitReason $reason) => ['kind' => $reason->kind->value, 'severity' => $reason->severity(), 'text' => SuggestEdits::text($reason->message(), sentence: false)], $reasons),
            'score' => $row->score,
            'priority' => Priority::word($row->score),
            'priorityLabel' => SuggestEdits::text(new Message('revisit.priority.' . Priority::word($row->score))),
            'editUrl' => $row->editUrl,
            'reviewUrl' => $row->editUrl ? $row->editUrl . (str_contains($row->editUrl, '?') ? '&' : '?') . 'ghostwriter=suggest' : null,
        ];
    }

    /**
     * Sections Ghostwriter writes for whose entries this person may see.
     *
     * @return array<string, Section> By handle.
     */
    private function visibleSections(): array
    {
        $user = Craft::$app->getUser()->getIdentity();
        $visible = [];

        foreach (Plugin::getInstance()->types->sections() as $section) {
            if ($user?->can("viewEntries:{$section->uid}")) {
                $visible[$section->handle] = $section;
            }
        }

        return $visible;
    }
}
