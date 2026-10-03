<?php
// Deletes the spike's tokens (routes gwspike/render) and their cached payloads.
require __DIR__.'/console.php';
$rows = (new craft\db\Query)->from('{{%tokens}}')->select(['id', 'route'])->all();
$n = 0;
foreach ($rows as $r) {
    $route = json_decode($r['route'], true);
    if (($route[0] ?? null) !== 'gwspike/render') continue;
    Craft::$app->getCache()->delete('gwspike:'.($route[1]['key'] ?? ''));
    Craft::$app->getTokens()->deleteTokenById((int) $r['id']); $n++;
}
echo "deleted $n spike tokens; ", count($rows) - $n, " other tokens untouched\n";
