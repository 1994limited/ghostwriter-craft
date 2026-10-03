<?php
// php spikes/preview/setup.php [teardown]
// Creates the spike's OWN entries (never touches existing ones):
//  - existing: a disabled Pages entry (not in the nav, which lists live pages)
//  - newPage / newJournal: unpublished drafts, as the CP's "New entry" makes
// teardown hard-deletes them and anything they own.
require __DIR__.'/console.php';
require __DIR__.'/lib.php';
use craft\elements\Entry;

$elements = Craft::$app->getElements();
$user = Craft::$app->getUsers()->getUserById(1);

if (($argv[1] ?? null) === 'teardown') {
    foreach (spike_state() as $name => $id) {
        $e = Entry::find()->id($id)->status(null)->drafts(null)->provisionalDrafts(null)->trashed(null)->siteId('*')->one();
        foreach (Entry::find()->draftOf($id)->status(null)->provisionalDrafts(null)->all() as $d) $elements->deleteElement($d, true);
        echo $name, ' ', $id, ' ', $e ? ($elements->deleteElement($e, true) ? 'deleted' : 'FAILED') : 'gone', "\n";
    }
    spike_state([]);
    exit;
}

$pages = Craft::$app->getEntries()->getSectionByHandle('pages');
$journal = Craft::$app->getEntries()->getSectionByHandle('journal');

$existing = new Entry(['sectionId' => $pages->id, 'typeId' => $pages->getEntryTypes()[0]->id, 'siteId' => 1, 'authorId' => 1,
    'title' => 'GW spike existing page', 'slug' => 'gw-spike-existing-page', 'enabled' => false]);
$built = spike_build('page', $existing);
$existing->setFieldValues((new nineteenninetyfour\ghostwriter\drafts\FieldValues())->forCraft($built['data'], $built['schema']));
$found = Entry::find()->section('pages')->slug('gw-spike-existing-page')->status(null)->one();
if ($found) $existing = $found; else $elements->saveElement($existing) or exit('existing: '.json_encode($existing->getFirstErrors()));
spike_state(['existing' => $existing->id]);

$mk = function ($section) use ($elements) {
    $e = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'siteId' => 1, 'authorId' => 1]);
    $e->slug = craft\helpers\ElementHelper::tempSlug();          // as EntriesController::actionCreate()
    $e->setScenario(craft\base\Element::SCENARIO_ESSENTIALS);
    Craft::$app->getDrafts()->saveElementAsDraft($e, 1, null, null, false) or exit('draft: '.json_encode($e->getFirstErrors()));
    return $e;
};
$newPage = $mk($pages);
$newJournal = $mk($journal);
spike_state(['existing' => $existing->id, 'newPage' => $newPage->id, 'newJournal' => $newJournal->id]);
echo json_encode(['existing' => [$existing->id, $existing->uri], 'newPage' => [$newPage->id, $newPage->draftId, $newPage->uri], 'newJournal' => [$newJournal->id, $newJournal->draftId, $newJournal->uri]]), "\n";
