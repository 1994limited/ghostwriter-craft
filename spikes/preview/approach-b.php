<?php
// php spikes/preview/approach-b.php <existing|newPage>
// Approach B: a throwaway (non-provisional) draft holding the draft values,
// rendered by Craft's own preview/preview token over real HTTP, then
// hard-deleted. Measures the cost and what it leaves behind.
require __DIR__.'/console.php';
require __DIR__.'/lib.php';
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\helpers\StringHelper;

$case = $argv[1] ?? 'existing';
$id = spike_state()[$case];
$db = Craft::$app->getDb();
$count = fn () => [
    'elements' => (int) (new craft\db\Query)->from('{{%elements}}')->count(),
    'drafts' => (int) (new craft\db\Query)->from('{{%drafts}}')->count(),
    'queue' => (int) (new craft\db\Query)->from('{{%queue}}')->count(),
    'trashed' => (int) (new craft\db\Query)->from('{{%elements}}')->where(['not', ['dateDeleted' => null]])->count(),
    'searchindex' => (int) (new craft\db\Query)->from('{{%searchindex}}')->count(),
    'maxElementId' => (int) (new craft\db\Query)->from('{{%elements}}')->max('id'),
];
$before = $count();
$entry = Entry::find()->id($id)->status(null)->drafts(null)->one();
$t = ['start' => microtime(true)];
try {
    $built = spike_build('page', $entry);
    $draft = Craft::$app->getDrafts()->createDraft($entry, 1, 'Ghostwriter preview', null, [], false);
    $t['createDraft'] = microtime(true);
    $draft->title = $built['title'];
    $draft->setFieldValues((new nineteenninetyfour\ghostwriter\drafts\FieldValues())->forCraft($built['data'], $built['schema']));
    $draft->setScenario(craft\base\Element::SCENARIO_ESSENTIALS);
    Craft::$app->getElements()->saveElement($draft) or throw new RuntimeException(json_encode($draft->getFirstErrors()));
    $t['save'] = microtime(true);
} catch (Throwable $e) {
    echo json_encode(['case' => $case, 'error' => get_class($e).': '.$e->getMessage()]), "\n";
    exit;
}
$token = Craft::$app->getTokens()->createToken(['preview/preview', ['elementType' => Entry::class, 'canonicalId' => $entry->getCanonicalId(), 'siteId' => $entry->siteId, 'draftId' => $draft->draftId]], null, (new DateTime())->modify('+15 minutes'));
$url = UrlHelper::siteUrl($entry->uri, ['token' => $token, 'x-craft-preview' => Craft::$app->getSecurity()->hashData(StringHelper::randomString(10))], null, $entry->siteId);
$ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
$html = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$t['render'] = microtime(true);
$mid = $count();
Craft::$app->getElements()->deleteElement($draft, true);
Craft::$app->getTokens()->deleteTokenById((int) (new craft\db\Query)->from('{{%tokens}}')->where(['token' => $token])->scalar());
$t['delete'] = microtime(true);
$after = $count();
$ms = []; $prev = $t['start']; foreach ($t as $k => $v) { if ($k === 'start') continue; $ms[$k] = round(($v - $prev) * 1000); $prev = $v; }
file_put_contents(spike_out()."/craft-$case.b.html", $html);
echo json_encode(['case' => $case, 'status' => $status, 'ms' => $ms, 'total_ms' => round(($t['delete'] - $t['start']) * 1000),
    'title' => preg_match('#<title>(.*?)</title>#s', $html, $tt) ? $tt[1] : null,
    'counts' => ['before' => $before, 'while' => $mid, 'after' => $after]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";
