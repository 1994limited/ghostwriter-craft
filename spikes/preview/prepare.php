<?php
// php spikes/preview/prepare.php <existing|newPage|newJournal> [--no-markers] [--nested] [--route=gwspike/render]
// Approach A, step 1 (the CP side, `ghostwriter/preview/prepare` in §7.3):
// build the values exactly as Applier does, mark a preview copy, store them
// under a random key, and make a Craft token routed to the render action.
// Nothing about the entry is saved.
require __DIR__.'/console.php';
require __DIR__.'/lib.php';
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\helpers\StringHelper;

$case = $argv[1] ?? 'newPage';
$marked = ! in_array('--no-markers', $argv, true);
$nested = in_array('--nested', $argv, true);
$id = spike_state()[$case] ?? exit("run setup.php first\n");

// The person's provisional draft if there is one (never made here), else the entry or its unpublished draft.
$entry = Entry::find()->draftOf($id)->provisionalDrafts()->draftCreator(1)->status(null)->one()
    ?? Entry::find()->id($id)->status(null)->drafts(null)->one();
$before = ['dateUpdated' => $entry->dateUpdated->format(DATE_ATOM), 'drafts' => Entry::find()->drafts()->status(null)->count(), 'entries' => Entry::find()->status(null)->drafts(null)->provisionalDrafts(null)->count()];

$t0 = microtime(true);
$built = spike_build($case === 'newJournal' ? 'journal' : 'page', $entry);
$markers = new SpikeMarkers;
$data = $marked ? $markers->mark($built['data'], $built['schema']) : $built['data'];

$nestedSpec = [];
if ($nested) {
    // A CKEditor nested entry the draft would add (an inline pull quote). It has no id: -101 is a preview-only placeholder.
    $html = '<craft-entry data-entry-id="-101">&nbsp;</craft-entry>';
    if ($case === 'newJournal') $data['body'] .= $html;
    else foreach ($data['pageBuilder'] as $i => $b) if ($b['type'] === 'text') { $data['pageBuilder'][$i]['text'] .= $html; break; }
    $q = $marked ? SpikeMarkers::code('n101.0') : ''; $a = $marked ? SpikeMarkers::code('n101.1') : '';
    $nestedSpec[-101] = ['type' => 'quoteBlock', 'fields' => ['quote' => $q.'A nested entry that was never saved.', 'attribution' => $a.'The spike']];
    $markers->map['n101'] = ['path' => 'body/nested/-101', 'label' => 'quoteBlock (CKEditor nested entry)', 'parent' => $case === 'newJournal' ? 'f:body' : 'b1'];
}
$values = (new nineteenninetyfour\ghostwriter\drafts\FieldValues())->forCraft($data, $built['schema']);

// Title and slug: a new entry's __temp_ slug is replaced in memory from the draft title, and the URI recomputed on the clone.
$clone = clone $entry;
$clone->title = $built['title'];
if (ElementHelper::isTempSlug($clone->slug)) $clone->slug = ElementHelper::generateSlug($built['title']);
$uriBefore = $entry->uri;
Craft::$app->getElements()->setElementUri($clone);
$mapMs = (microtime(true) - $t0) * 1000;

$key = StringHelper::randomString(32);
if ($marked) $markers->map['f:title'] = ['path' => 'title', 'label' => 'Title'];
Craft::$app->getCache()->set('gwspike:'.$key, ['id' => $entry->id, 'siteId' => $entry->siteId, 'title' => ($marked ? SpikeMarkers::code('f:title') : '').$built['title'], 'slug' => $clone->slug, 'values' => $values, 'nested' => $nestedSpec], 900);
$route = preg_match('/--route=(\S+)/', implode(' ', $argv), $m) ? $m[1] : 'gwspike/render';
$token = Craft::$app->getTokens()->createToken([$route, ['key' => $key]], null, (new DateTime())->modify('+15 minutes'));
$general = Craft::$app->getConfig()->getGeneral();
$url = UrlHelper::siteUrl($clone->uri, [$general->tokenParam => $token, 'x-craft-preview' => Craft::$app->getSecurity()->hashData(StringHelper::randomString(10))], null, $entry->siteId);

$after = ['dateUpdated' => Entry::find()->id($entry->id)->status(null)->drafts(null)->provisionalDrafts(null)->one()->dateUpdated->format(DATE_ATOM), 'drafts' => Entry::find()->drafts()->status(null)->count(), 'entries' => Entry::find()->status(null)->drafts(null)->provisionalDrafts(null)->count()];
$out = spike_out();
file_put_contents("$out/craft-$case.url", $url);
file_put_contents("$out/craft-$case.map.json", json_encode($markers->map, JSON_UNESCAPED_UNICODE));
echo json_encode(['case' => $case, 'entry' => [$entry->id, $entry->draftId, $entry->getIsUnpublishedDraft() ? 'unpublished draft' : ($entry->isProvisionalDraft ? 'provisional' : 'canonical')],
    'uri' => [$uriBefore, $clone->uri], 'ms_map' => round($mapMs), 'saved_nothing' => $before === $after, 'before' => $before, 'after' => $after,
    'url' => preg_replace('/token=\w+/', 'token=…', $url), 'blocks' => count($markers->map)], JSON_UNESCAPED_SLASHES), "\n";
