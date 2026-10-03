// The Preview tab's helpers and its "Updating preview…" state: run with `node --test tests/js`.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import vm from 'node:vm';

const dist = new URL('../../src/web/assets/cp/dist/', import.meta.url);
const plain = (value) => JSON.parse(JSON.stringify(value));
const sha = (url) => createHash('sha256').update(readFileSync(url)).digest('hex');

test('locator.js is core’s copy, unchanged', () => {
    assert.equal(sha(new URL('locator.js', dist)), sha(new URL('../../vendor/1994/ghostwriter-core/resources/js/preview/locator.js', import.meta.url)), 'Copy resources/js/preview/locator.js from ghostwriter-core again.');
});

test('markers.js (the gap chips) is core’s copy, unchanged', () => {
    assert.equal(sha(new URL('markers.js', dist)), sha(new URL('../../vendor/1994/ghostwriter-core/resources/js/preview/markers.js', import.meta.url)), 'Copy resources/js/preview/markers.js from ghostwriter-core (1.8.2 or later) again.');
});

// ---- A very small DOM: just what preview.js touches ------------------------

class El {
    constructor(tag = 'div') {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.attributes = {};
        this.dataset = {};
        this.style = {};
        this.listeners = {};
        this.hidden = false;
        this.parts = {};
        this.textContent = '';
        this.innerHTML = '';
        this.clientWidth = 800;
        this.clientHeight = 600;
        const classes = new Set();
        this.classList = { add: (c) => classes.add(c), remove: (c) => classes.delete(c), contains: (c) => classes.has(c), toggle: (c, on) => (on ? classes.add(c) : classes.delete(c)) };
    }
    set className(value) { value.split(' ').forEach((c) => this.classList.add(c)); }
    querySelector(selector) { return (this.parts[selector] ??= new El()); }
    querySelectorAll(selector) { return selector === 'iframe' ? this.children.filter((c) => c.tagName === 'IFRAME') : []; }
    setAttribute(name, value) { this.attributes[name] = String(value); }
    getAttribute(name) { return this.attributes[name] ?? null; }
    appendChild(child) { this.children.push(child); child.parent = this; return child; }
    append(...children) { children.forEach((c) => this.appendChild(c)); }
    remove() { if (this.parent) this.parent.children = this.parent.children.filter((c) => c !== this); this.removed = true; }
    addEventListener(type, handler) { (this.listeners[type] ??= []).push(handler); }
    removeEventListener() {}
    fire(type) { (this.listeners[type] ?? []).forEach((h) => h({ type, target: this })); }
    attachShadow() { return new El(); }
    contains(other) { return other === this; }
}

function frameDocument({ error = null } = {}) {
    const body = new El('body');
    body.childNodes = [new El('p')];
    if (error) body.attributes['data-ghostwriter-preview-error'] = error;
    const doc = new El('#document');
    doc.body = body;
    doc.documentElement = new El('html');
    doc.createElement = (tag) => new El(tag);
    doc.fonts = { ready: Promise.resolve(), addEventListener() {}, removeEventListener() {} };
    return doc;
}

function harness() {
    const announced = [];
    const requests = [];
    const window = {};
    const document = { createElement: (tag) => new El(tag), currentScript: { src: 'https://cp.test/cpresources/x/preview.js' } };
    window.performance = { now: () => 0 };
    const context = vm.createContext({
        window, document, URL, console, setTimeout, clearTimeout, Promise,
        performance: window.performance,
        ResizeObserver: class { observe() {} disconnect() {} },
        Craft: { sendActionRequest: (method, action, options) => new Promise((resolve, reject) => requests.push({ options, resolve, reject })) },
    });
    context.window = context;
    context.Ghostwriter = { escape: (s) => String(s ?? '') };
    // No module loading in the test: a locator that finds nothing.
    context.Ghostwriter.previewLocator = () => Promise.resolve({
        findMarkers: () => ({ marks: [] }),
        locate: () => ({ regions: [], byKey: {}, missing: [], partial: false }),
        measure: () => null,
        watch: () => ({ stop() {} }),
        contentArea: (doc) => doc.body,
    });
    context.Ghostwriter.previewMarkers = () => Promise.resolve({ markGaps: () => [], countByRegion: () => ({}) });
    vm.runInContext(readFileSync(new URL('preview.js', dist), 'utf8'), context);
    const preview = new context.Ghostwriter.PagePreview({ target: () => ({ id: 's', elementId: 1, siteId: 1 }), announce: (text) => announced.push(text) });
    const status = () => !preview.root.querySelector('[data-status]').hidden;
    const busyAttr = () => preview.root.querySelector('[data-window]').getAttribute('aria-busy');
    const frames = () => preview.stage.children.filter((c) => c.tagName === 'IFRAME' && !c.removed);
    const loadFrame = (frame, doc = frameDocument()) => {
        frame.contentDocument = doc;
        frame.contentWindow = { performance: { getEntriesByType: () => [{ responseStatus: 200, serverTiming: [] }] }, scrollY: 0, scrollTo() {}, ResizeObserver: class { observe() {} disconnect() {} }, addEventListener() {}, removeEventListener() {}, requestAnimationFrame: (f) => setTimeout(f) };
        frame.fire('load');
    };
    const settle = () => new Promise((r) => setTimeout(r, 5));
    return { preview, requests, announced, status, busyAttr, frames, loadFrame, settle, H: context.Ghostwriter.PreviewHelpers };
}

test('“Updating preview…” shows only while a render is on its way', async () => {
    const h = harness();
    h.preview.root.hidden = false;

    h.preview.render('draft 1');
    assert.equal(h.status(), true);
    assert.equal(h.busyAttr(), 'true');
    assert.equal(h.announced.at(-1), 'Updating preview…');

    h.requests[0].resolve({ data: { preview: true, url: 'https://site.test/page?token=x', map: [], ms: 5, reused: false } });
    await h.settle();
    assert.equal(h.status(), true, 'Still on its way while the frame loads.');

    h.loadFrame(h.frames()[0]);
    await h.settle();
    assert.equal(h.status(), false, 'Gone once the page has loaded and its blocks are found.');
    assert.equal(h.busyAttr(), 'false');
    assert.equal(h.announced.at(-1), 'Preview updated.');

    // Desktop/Phone changes nothing about it.
    h.preview.setWidth('phone');
    assert.equal(h.status(), false);
});

test('a render replaced by a newer one leaves only the newer one’s state', async () => {
    const h = harness();
    h.preview.root.hidden = false;

    h.preview.render('draft 1');
    h.preview.render('draft 2');
    h.requests[0].resolve({ data: { preview: true, url: 'https://site.test/one', map: [], ms: 1 } });
    await h.settle();
    assert.equal(h.frames().length, 0, 'The older answer loads nothing.');
    assert.equal(h.status(), true);

    h.requests[1].resolve({ data: { preview: true, url: 'https://site.test/two', map: [], ms: 1 } });
    await h.settle();
    h.loadFrame(h.frames()[0]);
    await h.settle();
    assert.equal(h.status(), false);
    assert.equal(h.preview.rendered, 'draft 2');
});

test('it goes when the render fails, the page can’t be shown, or the piece changes', async () => {
    let h = harness();
    h.preview.root.hidden = false;
    h.preview.render('draft');
    h.requests[0].resolve({ data: { preview: true, url: 'https://site.test/x', map: [], ms: 1 } });
    await h.settle();
    h.loadFrame(h.frames()[0], frameDocument({ error: 'Unknown method "nope".' }));
    await h.settle();
    assert.equal(h.status(), false);
    assert.equal(h.preview.root.dataset.state, 'error');
    assert.match(h.announced.at(-1), /couldn’t render this draft: Unknown method "nope"\./);
    assert.ok(!h.announced.includes('Preview updated.'));

    h = harness();
    h.preview.root.hidden = false;
    h.preview.render('draft');
    h.requests[0].resolve({ data: { preview: false, reason: 'no-urls', message: 'Pages in this section have no address.' } });
    await h.settle();
    assert.equal(h.status(), false);
    assert.equal(h.preview.root.dataset.state, 'unavailable');

    h = harness();
    h.preview.root.hidden = false;
    h.preview.render('draft');
    h.requests[0].reject(new Error('offline'));
    await h.settle();
    assert.equal(h.status(), false);

    h = harness();
    h.preview.root.hidden = false;
    h.preview.render('draft');
    h.preview.reset();
    assert.equal(h.status(), false);
});

test('a page-level field is outlined where the page shows it, not in the nav', () => {
    const { H } = harness();
    const main = new El('main');
    const navLink = new El('a');
    const h1 = new El('h1');
    main.contains = (el) => el === h1;
    const map = [{ key: 'f1', kind: 'field' }, { key: 'b1', kind: 'block' }];
    const furniture = (el) => el === navLink;
    const marks = [{ key: 'f1', element: navLink }, { key: 'f1', element: h1 }, { key: 'b1', element: navLink }];

    assert.deepEqual(plain(H.pickMarks(marks, map, main, furniture).map((m) => [m.key, m.element.tagName])), [['f1', 'H1'], ['b1', 'A']]);
    // Printed only in the nav (a new page's title): not on the page.
    assert.deepEqual(plain(H.pickMarks([marks[0]], map, main, furniture)), []);
});

test('the frame says whether it is the page, a refusal or the render’s error', () => {
    const { H } = harness();

    assert.deepEqual(plain(H.readFrame(null, 0)), { ok: false, kind: 'refused' });
    assert.equal(H.readFrame(frameDocument(), 200).ok, true);
    assert.equal(H.readFrame(frameDocument(), 500).kind, 'error');

    const doc = frameDocument({ error: 'Variable "x" does not exist.' });
    doc.body.attributes['data-ghostwriter-preview-class'] = 'Twig\\Error\\RuntimeError';
    doc.body.attributes['data-ghostwriter-preview-template'] = 'journal/_entry:9';
    assert.deepEqual(plain(H.readFrame(doc, 500)), { ok: false, kind: 'error', message: 'Variable "x" does not exist.', detail: 'Twig\\Error\\RuntimeError · journal/_entry:9' });
});

test('Desktop scales a 1280 px layout into a phone-width panel; Phone is 390 px', () => {
    const { H } = harness();

    assert.deepEqual(plain(H.fit('desktop', 692)), { width: 692, scale: 1 });
    assert.deepEqual(plain(H.fit('desktop', 320)), { width: 1280, scale: 0.25 });
    assert.deepEqual(plain(H.fit('phone', 692)), { width: 390, scale: 1 });
    assert.deepEqual(plain(H.fit('phone', 320)), { width: 320, scale: 1 });
    assert.equal(H.label({ key: 's2', label: 'Where to put one', parent: 'f3' }, { f3: { key: 'f3', label: 'Body' } }), 'Where to put one · in Body');
});
