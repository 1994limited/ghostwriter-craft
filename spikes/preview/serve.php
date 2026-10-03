<?php
// php spikes/preview/serve.php <url-file> [--headers]
// Runs ONE front-end request through gw-test-craft's real web application,
// in this process, with the spike's controller registered: the render half
// of approach A needs plugin code in the web process, which the spike may
// not add to the site or the addon's main checkout.
$url = trim(file_get_contents($argv[1]));
$u = parse_url($url);
parse_str($u['query'] ?? '', $_GET);
$site = getenv('GW_SITE') ?: getenv('HOME').'/Dev/gw-test-craft';
$_SERVER = array_merge($_SERVER, ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => $u['host'], 'SERVER_NAME' => $u['host'], 'HTTPS' => 'on', 'SERVER_PORT' => 443,
    'REQUEST_URI' => $u['path'].'?'.$u['query'], 'QUERY_STRING' => $u['query'], 'SCRIPT_NAME' => '/index.php', 'PHP_SELF' => '/index.php',
    'SCRIPT_FILENAME' => $site.'/web/index.php', 'DOCUMENT_ROOT' => $site.'/web', 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_ACCEPT' => 'text/html', 'HTTP_USER_AGENT' => 'gw-spike']);
require $site.'/bootstrap.php';
require __DIR__.'/SpikePreviewController.php';
/** @var craft\web\Application $app */
$app = require CRAFT_VENDOR_PATH.'/craftcms/cms/bootstrap/web.php';
$app->controllerMap["gwspike"] = SpikePreviewController::class;
$app->getRequest()->setIsConsoleRequest(false); // PHP_SAPI is cli here
// The site has no partial for nested entries; the spike brings one without adding files to the site.
yii\base\Event::on(craft\web\View::class, craft\web\View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, fn ($e) => $e->roots['_partials'] = __DIR__.'/templates');
if (in_array('--headers', $argv, true)) SpikePreviewController::$headers = [
    'Content-Security-Policy' => "script-src 'self' 'unsafe-inline' 'unsafe-eval'; connect-src 'self'; form-action 'none'; frame-ancestors 'self'; base-uri 'self'",
    'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Frame-Options' => 'SAMEORIGIN', 'X-Ghostwriter-Preview' => '1'];
if (getenv("DEBUG")) { $r = $app->getRequest(); fwrite(STDERR, json_encode(["tokenParam" => $r->getQueryParam("token") ? "set" : "missing", "token" => $r->getToken() !== null, "route" => $r->getToken() ? Craft::$app->getTokens()->getTokenRoute($r->getToken()) : null, "path" => $r->getPathInfo(), "site" => $r->getIsSiteRequest()])."\n"); }
// What a site template would do with the preview flag, analytics, a form and a third-party script,
// added to the page in the site's own request context (the test site's templates have none of these).
yii\base\Event::on(craft\web\View::class, craft\web\View::EVENT_AFTER_RENDER_PAGE_TEMPLATE, function ($e) {
    if (! getenv('PROBE')) return;
    $probe = Craft::$app->getView()->renderString(<<<'TWIG'
<aside id="probe">
{% if craft.app.request.isPreview %}<p id="gw-flag">isPreview=yes ghostwriterPreview={{ (ghostwriterPreview ?? false) ? 'yes' : 'no' }}</p>{% endif %}
{% if not craft.app.request.isPreview %}<script id="analytics">/* would load analytics */</script>{% endif %}
<form method="post" id="spike-form"><input name="x"><button>Send</button></form>
<script src="https://analytics.example.invalid/gtm.js?id=GTM-TEST"></script>
</aside>
TWIG, $e->variables, craft\web\View::TEMPLATE_MODE_SITE);
    $e->output = str_replace('</main>', $probe.'</main>', $e->output);
});
$t = microtime(true);
ob_start();
try { $app->run(); } catch (Throwable $e) { ob_end_clean(); echo json_encode(['error' => get_class($e).': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine(), 'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 8), 'prev' => $e->getPrevious()?->getMessage()]), "\n"; exit(1); }
$html = ob_get_clean();
$ms = round((microtime(true) - $t) * 1000);
$out = getenv('OUT') ?: sys_get_temp_dir();
$name = basename($argv[1], '.url');
file_put_contents("$out/$name.a.html", $html);
preg_match_all('/\x{E0067}\x{E0077}([\x{E0020}-\x{E007E}]{1,16})\x{E007F}/u', $html, $m);
$keys = array_values(array_unique(array_map(fn ($c) => explode('.', implode('', array_map(fn ($x) => chr(mb_ord($x) - 0xE0000), mb_str_split($c))))[0], $m[1])));
$map = json_decode(@file_get_contents("$out/$name.map.json") ?: '{}', true);
$h = $app->getResponse()->getHeaders();
echo json_encode(['status' => $app->getResponse()->getStatusCode(), 'ms' => $ms, 'title' => preg_match('#<title>(.*?)</title>#s', $html, $tt) ? preg_replace('/\x{E0067}\x{E0077}[\x{E0020}-\x{E007E}]+\x{E007F}/u', '', $tt[1]) : null,
    'isPreview' => $app->getRequest()->getIsPreview(), 'markers' => ['expected' => count($map), 'found' => count(array_intersect(array_keys($map), $keys)), 'missing' => array_values(array_diff(array_keys($map), $keys))],
    'nested_rendered' => str_contains($html, 'A nested entry that was never saved'),
    'headers' => array_filter(['csp' => $h->get('content-security-policy'), 'xfo' => $h->get('x-frame-options'), 'cache' => $h->get('cache-control')])], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";
