// Spike locator (§8.2), run in the CP page against a same-origin frame.
// window.gwLocate(frameDocument, map) -> {found, regions, assets, stripped, ms}
window.gwLocate = function (doc, map) {
  const t0 = performance.now();
  const RE = /\u{E0067}\u{E0077}([\u{E0020}-\u{E007E}]{1,16})\u{E007F}/gu;
  const decode = (s) => [...s].map((c) => String.fromCharCode(c.codePointAt(0) - 0xE0000)).join('');
  const marks = new Map(); // key -> [elements]
  const add = (code, el) => { const key = decode(code).split('.')[0]; if (!marks.has(key)) marks.set(key, []); marks.get(key).push(el); };
  const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT);
  let n, textNodes = 0, stripped = 0;
  // Invisibility check: the rendered width of every marker, measured before stripping.
  const widths = [];
  { const w = doc.createTreeWalker(doc.body, NodeFilter.SHOW_TEXT); let t;
    while ((t = w.nextNode())) for (const m of t.data.matchAll(RE)) { const r = doc.createRange(); r.setStart(t, m.index); r.setEnd(t, m.index + m[0].length); widths.push(r.getBoundingClientRect().width); if (r.getBoundingClientRect().width > 0.5) (window.__wide = window.__wide || []).push([t.parentElement.tagName, getComputedStyle(t.parentElement).fontFamily.slice(0, 30), getComputedStyle(t.parentElement).letterSpacing, r.getBoundingClientRect().width, t.data.slice(m.index + m[0].length, m.index + m[0].length + 15)]); } }
  while ((n = walker.nextNode())) {
    textNodes++;
    if (!n.data.includes('\u{E0067}')) continue;
    for (const m of n.data.matchAll(RE)) add(m[1], n.parentElement);
    n.data = n.data.replace(RE, () => { stripped++; return ''; });
  }
  for (const el of doc.body.querySelectorAll('[alt],[title],[aria-label],[content]')) {
    for (const a of ['alt', 'title', 'aria-label', 'content']) {
      const v = el.getAttribute(a); if (!v || !v.includes('\u{E0067}')) continue;
      for (const m of v.matchAll(RE)) add(m[1], el);
      el.setAttribute(a, v.replace(RE, () => { stripped++; return ''; }));
    }
  }
  // <title> and <meta content> in the head are outside body: strip them too (not mapped)
  if (doc.title.includes('\u{E0067}')) { doc.title = doc.title.replace(RE, () => { stripped++; return ''; }); }
  for (const el of doc.head.querySelectorAll('meta[content]')) { const v = el.getAttribute('content'); if (v.includes('\u{E0067}')) el.setAttribute('content', v.replace(RE, () => { stripped++; return ''; })); }
  // asset match for blocks without marks
  const assets = {};
  for (const [key, b] of Object.entries(map)) {
    if (marks.has(key) || !(b.assets || []).length) continue;
    const el = [...doc.querySelectorAll('img,source')].find((i) => (b.assets || []).some((f) => (i.getAttribute('src') || i.getAttribute('srcset') || '').split('?')[0].endsWith(f)));
    if (el) { assets[key] = el.getAttribute('src'); marks.set(key, [el]); }
  }
  // regions: children inherit into parents (a block with only child marks)
  for (const [key, b] of Object.entries(map)) {
    if (b.parent && marks.has(key) && map[b.parent] === undefined) {/* top-level field */}
  }
  const lca = (els) => { let a = els[0]; while (a && !els.every((e) => a.contains(e))) a = a.parentElement; return a; };
  const regions = {};
  const keysOf = (pred) => Object.keys(map).filter(pred);
  const childMarks = (key) => keysOf((k) => k.startsWith(key + 's') || (map[k] && map[k].parent === key)).flatMap((k) => marks.get(k) || []);
  for (const key of Object.keys(map)) {
    const els = (marks.get(key) || []).concat(childMarks(key));
    if (!els.length) continue;
    let root = lca(els);
    // climb while no other sibling block's marks are inside
    const others = Object.keys(map).filter((k) => k !== key && (map[k].parent || null) === (map[key].parent || null)).flatMap((k) => marks.get(k) || []);
    while (root.parentElement && root.parentElement !== doc.body && !others.some((o) => root.parentElement.contains(o) && !root.contains(o))) root = root.parentElement;
    // shared container (unwrapped runs): fall back to the LCA itself
    if (others.some((o) => root.contains(o))) root = lca(els);
    const r = root.getBoundingClientRect();
    regions[key] = { tag: root.tagName.toLowerCase() + (root.className ? '.' + root.className : ''), x: Math.round(r.x + doc.defaultView.scrollX), y: Math.round(r.y + doc.defaultView.scrollY), w: Math.round(r.width), h: Math.round(r.height), el: root };
  }
  // Direct check: the same text with and without a marker, in the page's fonts.
  const sameWidth = ['Georgia, serif', 'system-ui, sans-serif'].map((font) => {
    const mk = (t) => { const sp = doc.createElement('span'); sp.style.cssText = `font:18px ${font};white-space:pre;position:absolute;left:-9999px`; sp.textContent = t; doc.body.appendChild(sp); const w = sp.getBoundingClientRect().width; sp.remove(); return w; };
    const code = '\u{E0067}\u{E0077}' + [...'b7.2'].map((c) => String.fromCodePoint(0xE0000 + c.charCodeAt(0))).join('') + '\u{E007F}';
    return { font, plain: mk('2 hours of sun in June'), marked: mk('2 hours ' + code + 'of sun in June'), markedStart: mk(code + '2 hours of sun in June') };
  });
  const leftover = (doc.body.innerHTML.match(RE) || []).length;
  const ms = Math.round((performance.now() - t0) * 10) / 10;
  return { sameWidth, markerWidths: { count: widths.length, max: Math.max(0, ...widths), wide: window.__wide || [] }, textNodes, stripped, leftover, found: [...marks.keys()], missing: Object.keys(map).filter((k) => !regions[k]), assets, regions, ms };
};
window.gwOutline = function (doc, regions, map) {
  const host = doc.createElement('div'); doc.documentElement.appendChild(host);
  const root = host.attachShadow({ mode: 'closed' });
  for (const [key, r] of Object.entries(regions)) {
    if (map[key] && map[key].parent && !map[key].label.startsWith('set:')) continue;
    const d = doc.createElement('div');
    const child = map[key] && map[key].parent;
    d.style.cssText = `position:absolute;left:${r.x}px;top:${r.y}px;width:${r.w}px;height:${r.h}px;outline:2px ${child ? 'dashed' : 'solid'} ${child ? '#d97706' : '#2563eb'};pointer-events:none;z-index:99999`;
    const l = doc.createElement('span'); l.textContent = key + ' · ' + (map[key] ? map[key].label : '');
    l.style.cssText = 'position:absolute;top:-18px;left:0;font:11px system-ui;background:#2563eb;color:#fff;padding:1px 4px';
    d.appendChild(l); root.appendChild(d);
  }
};
