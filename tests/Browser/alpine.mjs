// Alpine.js on cached pages, in a real browser: a component on a cached page starts, and so does one inside a
// dynamic part, which arrives after the page has loaded.
//
// Needs Node 22+ and Chrome with remote debugging, such as:
//   "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --remote-debugging-port=9341 --ignore-certificate-errors
// Run before a release:
//   node tests/Browser/alpine.mjs https://geo.ddev.site ../../templates [9341]
// It writes its own templates into the site's templates folder, and removes them afterwards.

import fs from 'node:fs';
import path from 'node:path';

const [, , base, templates, port = '9341'] = process.argv;
if (!base || !templates) {
    console.error('Usage: node tests/Browser/alpine.mjs <site URL> <templates folder> [Chrome debugging port]');
    process.exit(2);
}
const name = 'speedy-alpine-' + Math.random().toString(16).slice(2, 8);
const dir = path.join(templates, name);
fs.mkdirSync(dir);
fs.writeFileSync(path.join(dir, 'page.twig'), `<!doctype html><html><head><meta charset="utf-8">
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js"></script></head><body>
<div x-data="{ count: 1 }"><button id="page" x-on:click="count++" x-text="'Page ' + count">not started</button></div>
{{ craft.speedy.dynamic('${name}/_part') }}
</body></html>`);
fs.writeFileSync(path.join(dir, '_part.twig'), `<div x-data="{ shown: 'part started' }"><span id="part" x-text="shown">not started</span></div>`);

const url = `${base.replace(/\/$/, '')}/${name}/page`;
const results = [];
const check = (ok, what) => results.push([ok, what]);
let ws;
try {
    // Build it, then make sure the copy the browser gets is the cached one.
    await fetch(url);
    const cached = await fetch(url);
    check(['HIT', 'STATIC'].includes(cached.headers.get('x-speedy')), `the page is cached (X-Speedy: ${cached.headers.get('x-speedy')})`);

    const tab = await (await fetch(`http://localhost:${port}/json/new?about:blank`, { method: 'PUT' })).json();
    ws = new WebSocket(tab.webSocketDebuggerUrl);
    let id = 0;
    const pending = new Map();
    ws.onmessage = (e) => { const m = JSON.parse(e.data); if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); } };
    await new Promise((r) => (ws.onopen = r));
    const send = (method, params = {}) => new Promise((r) => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
    const evaluate = async (expression) => (await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true })).result?.result?.value;
    const errors = [];
    ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.method === 'Runtime.exceptionThrown') errors.push(m.params.exceptionDetails.text); });

    await send('Runtime.enable');
    await send('Page.enable');
    await send('Page.navigate', { url });
    // Wait for Alpine and the dynamic part, up to 10 seconds.
    const waitFor = (expression) => evaluate(`new Promise((resolve) => { const t = Date.now(); (function poll() { const v = ${expression}; if (v || Date.now() - t > 10000) return resolve(!!v); setTimeout(poll, 100); })(); })`);

    check(await waitFor(`document.getElementById('page')?.textContent === 'Page 1'`), 'a component on the cached page starts');
    await evaluate(`document.getElementById('page').click()`);
    check(await waitFor(`document.getElementById('page')?.textContent === 'Page 2'`), 'and reacts to a click');
    check(await waitFor(`document.getElementById('part')?.textContent === 'part started'`), 'a component inside a dynamic part starts');
    check(errors.length === 0, `no JavaScript errors${errors.length ? ': ' + errors.join('; ') : ''}`);
} finally {
    ws?.close();
    fs.rmSync(dir, { recursive: true, force: true });
}

for (const [ok, what] of results) {
    console.log(`${ok ? 'ok  ' : 'FAIL'}  ${what}`);
}
process.exit(results.every(([ok]) => ok) ? 0 : 1);
