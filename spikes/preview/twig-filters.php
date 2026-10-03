<?php
// php spikes/preview/twig-filters.php — which common Twig filters keep a marker, and what they do to the visible text.
require __DIR__.'/console.php';
require __DIR__.'/lib.php';
$m = SpikeMarkers::code('b7.2');
$v = getenv('SUFFIX') ? 'Shade, year-round interest and somewhere to wait.'.$m : $m.'Shade, year-round interest and somewhere to wait.';
$view = Craft::$app->getView();
$filters = getenv('FILTERS') ? explode(',', getenv('FILTERS')) : ['upper', 'lower', 'title', 'capitalize', 'striptags', 'escape', 'slice(0, 20)', 'length', 'kebab', 'camel', 'markdown', 'widont', 'replace({"Shade": "Sun"})', 'split(" ")|first', 'trim', 'nl2br', 'url_encode', 'json_encode'];
$out = [];
foreach ($filters as $f) {
    try { $r = $view->renderString('{{ v|'.$f.' }}', ['v' => $v], craft\web\View::TEMPLATE_MODE_SITE); } catch (Throwable $e) { $r = 'ERR '.$e->getMessage(); }
    $kept = (bool) preg_match(SpikeMarkers::PATTERN, html_entity_decode($r));
    $out[$f] = ['kept' => $kept, 'visible' => mb_substr(preg_replace(SpikeMarkers::PATTERN, '', html_entity_decode($r)), 0, 40)];
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
