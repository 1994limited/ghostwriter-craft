// Headless Chrome over CDP, no dependencies (Node 22 WebSocket).
// node browser.mjs <config.json>
// config: {cms, base, loginPath, login: {userSel, passSel, submitSel}, hostPath,
//          previewUrl, map, out, headers: {..} | null, shot}
// Credentials come from GW_USER / GW_PASS in the environment.
import { spawn } from 'node:child_process';
import { readFileSync, writeFileSync, mkdirSync } from 'node:fs';
import { setTimeout as sleep } from 'node:timers/promises';

const cfg = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const port = 9333 + Math.floor(Math.random() * 500);
mkdirSync(cfg.out + '/chrome', { recursive: true });
const chrome = spawn('/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', [
  '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${cfg.out}/chrome-${port}`, '--ignore-certificate-errors',
  '--window-size=1400,1000', '--no-first-run', 'about:blank'], { stdio: 'ignore' });
process.on('exit', () => chrome.kill());
process.on('uncaughtException', (e) => { console.error(e); process.exit(1); });
process.on('unhandledRejection', (e) => { console.error(e); process.exit(1); });
let targets;
for (let i = 0; i < 50; i++) { try { targets = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json(); break; } catch { await sleep(200); } }
const ws = new WebSocket(targets.find((t) => t.type === 'page').webSocketDebuggerUrl);
await new Promise((r) => ws.addEventListener('open', r));
let id = 0; const pending = new Map(); const listeners = [];
ws.addEventListener('message', (e) => {
  const m = JSON.parse(e.data);
  if (m.id && pending.has(m.id)) { const p = pending.get(m.id); pending.delete(m.id); m.error ? p.rej(new Error(JSON.stringify(m.error))) : p.res(m.result); }
  else listeners.forEach((l) => l(m));
});
const send = (method, params = {}) => Promise.race([new Promise((_, rej) => setTimeout(() => rej(new Error('CDP timeout ' + method)), 40000)), send0(method, params)]);
const send0 = (method, params = {}) => new Promise((res, rej) => { const i = ++id; pending.set(i, { res, rej }); ws.send(JSON.stringify({ id: i, method, params })); });
const evaluate = async (expr) => { const r = await send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true }); if (r.exceptionDetails) throw new Error(JSON.stringify(r.exceptionDetails).slice(0, 500)); return r.result.value; };
const goto = async (url) => { await send('Page.navigate', { url }); await sleep(2500); };

const log = { console: [], blocked: [], requests: [] };
listeners.push((m) => {
  if (m.method === 'Runtime.consoleAPICalled') log.console.push(m.params.args.map((a) => a.value).join(' '));
  if (m.method === 'Log.entryAdded') log.console.push(`[${m.params.entry.source}] ${m.params.entry.text}`);
  if (m.method === 'Network.loadingFailed' && m.params.blockedReason) log.blocked.push(m.params.blockedReason);
  if (m.method === 'Network.requestWillBeSent') log.requests.push(m.params.request.url.replace(/token=[^&]+/, 'token=…').slice(0, 140));
  if (m.method === 'Fetch.requestPaused' && cfg.serveHtml && !m.params.responseStatusCode) {
    // Serves a page rendered in-process (Craft approach A) at a same-origin URL, with the policy headers.
    const headers = [{ name: 'Content-Type', value: 'text/html; charset=utf-8' }, ...Object.entries(cfg.headers || {}).map(([name, value]) => ({ name, value }))];
    send('Fetch.fulfillRequest', { requestId: m.params.requestId, responseCode: 200, responseHeaders: headers, body: readFileSync(cfg.serveHtml).toString('base64') }).catch((e) => console.error('serve failed', String(e)));
    return;
  }
  if (m.method === 'Fetch.requestPaused') {
    // Simulates Ghostwriter's preview middleware: the policy headers on the preview document only.
    const h = (m.params.responseHeaders || []).filter((x) => !/^(x-frame-options|content-security-policy)$/i.test(x.name));
    for (const [name, value] of Object.entries(cfg.headers || {})) h.push({ name, value });
    (async () => { const b = await send('Fetch.getResponseBody', { requestId: m.params.requestId }); await send('Fetch.fulfillRequest', { requestId: m.params.requestId, responseCode: m.params.responseStatusCode, responseHeaders: h, body: b.base64Encoded ? b.body : Buffer.from(b.body).toString('base64') }); console.error('fulfilled with policy headers'); })().catch((e) => console.error('fulfil failed', String(e).slice(0, 300)));
  }
});
await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable'); await send('Network.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 1000, deviceScaleFactor: 1, mobile: false });

// sign in
await goto(cfg.base + cfg.loginPath);
if (process.env.INSPECT) { await sleep(3000); console.log(await evaluate(`[...document.querySelectorAll('input,button')].map((e) => [e.tagName, e.type, e.name, e.id, e.textContent.trim().slice(0,20)].join('|')).join('\\n')`)); ws.close(); chrome.kill(); process.exit(0); }
await evaluate(`(async () => {
  for (let i = 0; i < 50 && !document.querySelector(${JSON.stringify(cfg.login.passSel)}); i++) await new Promise((r) => setTimeout(r, 200));
  const set = (sel, v) => { const el = document.querySelector(sel); const d = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value'); d.set.call(el, v); el.dispatchEvent(new Event('input', {bubbles: true})); el.dispatchEvent(new Event('change', {bubbles: true})); };
  set(${JSON.stringify(cfg.login.userSel)}, ${JSON.stringify(process.env.GW_USER)});
  set(${JSON.stringify(cfg.login.passSel)}, ${JSON.stringify(process.env.GW_PASS)});
  document.querySelector(${JSON.stringify(cfg.login.submitSel)}).click();
})()`);
await sleep(4000);
await goto(cfg.base + cfg.hostPath);
const signedIn = await evaluate('location.pathname');
console.error('signed in at', signedIn);

if (cfg.serveHtml) await send('Fetch.enable', { patterns: [{ urlPattern: cfg.interceptPattern, requestStage: 'Request', resourceType: 'Document' }] });
else if (cfg.headers) await send('Fetch.enable', { patterns: [{ urlPattern: cfg.interceptPattern || '*token=*', requestStage: 'Response', resourceType: 'Document' }] });
log.requests = []; log.blocked = []; log.console = [];
const locator = readFileSync(new URL('./locator.js', import.meta.url), 'utf8');
await evaluate(locator);
const t0 = Date.now();
const result = await evaluate(`new Promise((resolve) => {
  const f = document.createElement('iframe');
  f.setAttribute('sandbox', 'allow-same-origin allow-scripts');
  f.setAttribute('referrerpolicy', 'no-referrer');
  f.style.cssText = 'position:fixed;left:0;top:0;width:1280px;height:1000px;border:0;z-index:2147483647;background:#fff';
  f.addEventListener('load', () => {
    const loadMs = ${'Date.now()'} - window.__t0;
    let doc = null, err = null;
    try { doc = f.contentDocument; } catch (e) { err = String(e); }
    if (!doc || !doc.body) return resolve({ sameOrigin: false, err });
    const map = ${JSON.stringify(cfg.map)};
    const r = window.gwLocate(doc, map);
    window.gwOutline(doc, r.regions, map);
    // forms: sandbox without allow-forms must block submission
    let formBlocked = null;
    const form = doc.querySelector('form');
    if (form) { const before = doc.location.href; try { form.requestSubmit(); } catch (e) {} formBlocked = 'tried'; }
    const flag = doc.getElementById('gw-flag');
    const out = { sameOrigin: true, loadMs, url: doc.location.pathname, title: doc.title, flag: flag && flag.textContent,
      analyticsTag: !!doc.getElementById('analytics'), parentReach: (() => { try { return !!f.contentWindow.parent.document.body; } catch (e) { return 'blocked: ' + e; } })(),
      locator: { ...r, regions: Object.fromEntries(Object.entries(r.regions).map(([k, v]) => [k, (({ el, ...rest }) => rest)(v)])) } };
    resolve(out);
  });
  setTimeout(() => resolve({ timeout: true, frame: (() => { try { return f.contentDocument && f.contentDocument.location.href.slice(0, 80); } catch (e) { return String(e); } })() }), 20000);
  window.__t0 = Date.now();
  f.src = ${JSON.stringify(cfg.previewUrl)};
  document.body.appendChild(f);
})`);
await sleep(1500);
const after = await evaluate(`(() => { const f = document.querySelector('iframe[sandbox]'); return { frameUrl: f.contentDocument && f.contentDocument.location.pathname, parentUrl: location.pathname }; })()`);
if (cfg.shot) {
  const s = await send('Page.captureScreenshot', { format: 'png', clip: { x: 0, y: 0, width: 1280, height: cfg.shotHeight || 1000, scale: 1 } });
  writeFileSync(cfg.shot, Buffer.from(s.data, 'base64'));
}
console.log(JSON.stringify({ signedIn, ...result, after, blocked: log.blocked, console: log.console.slice(0, 12),
  thirdParty: log.requests.filter((u) => !u.startsWith(cfg.base)) }, null, 2));
ws.close(); chrome.kill();
