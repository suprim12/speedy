<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { withBase } from 'vitepress'

// ---------------------------------------------------------------------------
// Hero: a small site. Each page records Craft's cache tags; saving something clears the pages that share a tag.
// ---------------------------------------------------------------------------

const PAGES = [
  { url: '/', tags: ['section:news', 'global:footer'] },
  { url: '/news', tags: ['section:news', 'global:footer'] },
  { url: '/news/summer-sale', tags: ['entry:42', 'global:footer'] },
  { url: '/news/winter-menu', tags: ['entry:43', 'global:footer'] },
  { url: '/about', tags: ['entry:7', 'global:footer'] },
  { url: '/contact', tags: ['entry:8', 'global:footer'] },
  { url: '/shop/veg-box', tags: ['product:90', 'global:footer'] },
]

const ACTIONS = [
  { id: 'sale', label: 'Save “Summer Sale”', note: 'A News entry', tags: ['entry:42', 'section:news'], log: 'Saved entry “Summer Sale”' },
  { id: 'about', label: 'Save “About us”', note: 'A single page', tags: ['entry:7'], log: 'Saved entry “About us”' },
  { id: 'stock', label: 'Sell the last veg box', note: 'Stock changes', tags: ['product:90'], log: 'Saved variant “Seasonal veg box”' },
  { id: 'draft', label: 'Autosave a draft', note: 'While editing', tags: [], log: 'Draft saved: nothing cleared' },
  { id: 'footer', label: 'Edit the footer', note: 'A global set', tags: ['global:footer'], log: 'Saved global set “Footer”' },
]

const state = ref(Object.fromEntries(PAGES.map((p) => [p.url, 'cached'])))
const last = ref(null)
let timers = []

function run(action) {
  timers.forEach(clearTimeout)
  timers = []
  const hit = PAGES.filter((p) => p.tags.some((t) => action.tags.includes(t))).map((p) => p.url)
  state.value = Object.fromEntries(PAGES.map((p) => [p.url, hit.includes(p.url) ? 'rebuilding' : 'cached']))
  last.value = { action, count: hit.length }
  hit.forEach((url, i) => {
    timers.push(setTimeout(() => { state.value = { ...state.value, [url]: 'rebuilt' } }, 650 + i * 260))
  })
}

const label = (s) => ({ cached: 'Cached', rebuilding: 'Rebuilding…', rebuilt: 'Rebuilt' }[s])

// ---------------------------------------------------------------------------
// Speed: measured on our test site
// ---------------------------------------------------------------------------

const TIMES = [
  { name: 'Built by Craft', ms: 316, note: 'No cache' },
  { name: 'From Speedy, through PHP', ms: 30, note: 'Works as soon as it’s installed' },
  { name: 'From Speedy, by the web server', ms: 1, note: 'With the server rules' },
]
const bar = (ms) => `${Math.max(1.2, (ms / 316) * 100)}%`

// ---------------------------------------------------------------------------
// How it works, and the footer
// ---------------------------------------------------------------------------

const step = ref(0)
const copied = ref('')
async function copy(text, key) {
  try {
    await navigator.clipboard.writeText(text)
    copied.value = key
    setTimeout(() => (copied.value = ''), 1600)
  } catch {
    /* clipboard unavailable: the text is still selectable */
  }
}

let observer
onMounted(() => {
  observer = new IntersectionObserver((entries) => {
    for (const entry of entries) {
      if (entry.isIntersecting) step.value = Number(entry.target.dataset.step)
    }
  }, { rootMargin: '-45% 0px -45% 0px' })
  document.querySelectorAll('.sh-steps [data-step]').forEach((s) => observer.observe(s))
})
onBeforeUnmount(() => {
  observer?.disconnect()
  timers.forEach(clearTimeout)
})

// Twig's braces, output as text so Vue doesn't read them.
const open = '{' + '{'
const close = '}' + '}'

const installCmd = 'composer require suprim/speedy && php craft plugin/install speedy'
const rulesCmd = 'php craft speedy/server-rules'
</script>

<template>
  <div class="sh">
    <!-- Hero ------------------------------------------------------------- -->
    <section class="sh-hero">
      <div class="sh-wrap sh-hero__head" data-reveal>
        <h1>Fast pages, cleared just right</h1>
        <p class="sh-lead">Speedy Cache saves your Craft pages as static files and clears only the ones that change. No database tables, no template changes, and Cloudflare, CloudFront, Fastly, Bunny and Varnish built in. Save something below and see what clears.</p>
        <p class="sh-actions">
          <a class="sh-btn" :href="withBase('/getting-started/installation')">Get started</a>
          <a class="sh-link sh-link--light" :href="withBase('/guides/clearing')">How clearing works →</a>
        </p>
      </div>

      <div class="sh-wrap">
        <div class="sh-live" data-ui data-reveal>
          <div class="sh-live__acts" role="group" aria-label="Make a change">
            <p class="sh-live__label">In the control panel</p>
            <button v-for="a in ACTIONS" :key="a.id" type="button" class="sh-act" :aria-pressed="last?.action.id === a.id" @click="run(a)">
              <b>{{ a.label }}</b>
              <small>{{ a.note }}</small>
            </button>
          </div>
          <div class="sh-live__site">
            <p class="sh-live__label">Your site’s cached pages</p>
            <ul class="sh-pages" aria-live="polite">
              <li v-for="p in PAGES" :key="p.url" :class="'is-' + state[p.url]">
                <span class="sh-pages__url">{{ p.url }}</span>
                <span class="sh-pages__state">{{ label(state[p.url]) }}</span>
              </li>
            </ul>
            <p class="sh-live__log">
              <template v-if="last">
                <b>{{ last.action.log }}</b>
                <span v-if="last.count"> → {{ last.count }} of {{ PAGES.length }} pages rebuilt in the background. The rest stay cached.</span>
                <span v-else> Drafts never touch live pages.</span>
              </template>
              <template v-else>Choose a change on the left.</template>
            </p>
          </div>
        </div>
      </div>
    </section>

    <!-- Speed -------------------------------------------------------------- -->
    <section class="sh-split" aria-labelledby="sh-speed-title">
      <div class="sh-split__in">
        <h2 id="sh-speed-title" class="sh-split__title sh-split__title--a">316 ms,</h2>
        <div class="sh-times" data-ui>
          <div v-for="t in TIMES" :key="t.name" class="sh-time">
            <div class="sh-time__head"><span>{{ t.name }}</span><b>{{ t.ms }} ms</b></div>
            <div class="sh-time__track"><div class="sh-time__bar" :style="{ width: bar(t.ms) }"></div></div>
            <small>{{ t.note }}</small>
          </div>
          <p class="sh-times__note">Our store locator page, measured on our test site. Your times will vary; the gap won’t.</p>
        </div>
      </div>
      <div class="sh-split__out">
        <figure class="sh-split__shot" data-reveal>
          <img :src="withBase('/images/server-check.jpg')" width="706" height="130" alt="A message in the control panel: Cached pages are served without PHP, in 1 ms." loading="lazy">
          <figcaption>Utilities → Speedy Cache checks it for you.</figcaption>
        </figure>
        <p class="sh-split__title sh-split__title--b" aria-hidden="true">then 1 ms.</p>
      </div>
    </section>

    <!-- Features ------------------------------------------------------------ -->
    <section class="sh-wrap sh-features" aria-labelledby="sh-features-title">
      <h2 id="sh-features-title" data-reveal>Fast, without the usual worries</h2>
      <div class="sh-cells">
        <article class="sh-cell" data-reveal>
          <div class="sh-cell__text"><h3>See why it cleared</h3><p>A plain-English log: what was saved, how many pages cleared, and which ones.</p></div>
          <img :src="withBase('/images/log.jpg')" width="1600" height="647" alt="Recent clears: Saved entry “Adelaide CBD Store” cleared 7 pages, listed underneath" loading="lazy">
        </article>
        <article class="sh-cell sh-cell--flip" data-reveal>
          <div class="sh-cell__text"><h3>Your database stays small</h3><p>Pages and what they depend on are files. Speedy adds no tables, so backups and deploys stay quick.</p></div>
          <pre class="sh-cell__code" data-ui><code><span class="sh-dim">web/speedy/</span>
  example.com/news/index.html
<span class="sh-dim">storage/speedy/</span>
  meta/example.com/news.json
  tags/3f/3f2a…e9.txt
  log.jsonl</code></pre>
        </article>
        <article class="sh-cell" data-reveal>
          <div class="sh-cell__text"><h3>Forms just work</h3><p>Cached pages get a fresh CSRF token as they load. No template changes, no “Bad request” on submit.</p></div>
          <pre class="sh-cell__code" data-ui><code><span class="sh-dim">{# Your template, unchanged #}</span>
&lt;form method="post"&gt;
  <span class="t-k" v-text="open"></span> csrfInput() <span class="t-k" v-text="close"></span>
  …
&lt;/form&gt;</code></pre>
        </article>
        <article class="sh-cell sh-cell--flip" data-reveal>
          <div class="sh-cell__text"><h3>Safe by default</h3><p>Logged-in visitors, carts, pages that set cookies and pages with query strings are never cached.</p></div>
          <pre class="sh-cell__code" data-ui><code>X-Speedy: HIT
X-Speedy: SKIP logged in
X-Speedy: SKIP cart
X-Speedy: SKIP query string
X-Speedy: STATIC</code></pre>
        </article>
        <article class="sh-cell" data-reveal>
          <div class="sh-cell__text"><h3>Editing never clears</h3><p>Drafts and autosaves are ignored. Imports of thousands of entries make one clear, not thousands.</p></div>
          <pre class="sh-cell__code" data-ui><code>$ php craft resave/entries --section=news
Done resaving entries.
<span class="sh-ok">→ 1 clear job queued</span></code></pre>
        </article>
        <article class="sh-cell sh-cell--flip" data-reveal>
          <div class="sh-cell__text"><h3>Two settings to start</h3><p>On, and URLs to never cache. CDN keys if you have one. Everything else has a sensible default.</p></div>
          <img :src="withBase('/images/settings.jpg')" width="1600" height="713" alt="Speedy Cache settings, General tab: caching on, never cache, and what happens when content changes" loading="lazy">
        </article>
      </div>
    </section>

    <!-- CDN ------------------------------------------------------------------- -->
    <section class="sh-shape" aria-labelledby="sh-cdn-title">
      <div class="sh-wrap sh-shape__grid">
        <div class="sh-cdn" data-reveal data-ui>
          <p class="sh-cdn__seen"><span class="sh-dot"></span>Your site is behind <b>CloudFront</b></p>
          <ol class="sh-cdn__list">
            <li><span>/news/summer-sale</span><span>/news</span><span>/</span></li>
            <li class="sh-cdn__arrow">38 pages changed in one import</li>
            <li><span>/news*</span><span>/shop*</span><span>/</span></li>
          </ol>
          <p class="sh-cdn__count"><b>3</b> paths sent, not 38 <span>· 214 of 1,000 free paths used this month</span></p>
        </div>
        <div class="sh-shape__text">
          <h2 id="sh-cdn-title" data-reveal>Your CDN, cleared for you</h2>
          <p data-reveal>Speedy spots your CDN from its headers. Add a token and the CDN keeps your pages too, at the edge, close to visitors. When content changes, Speedy clears it there as well.</p>
          <ul class="sh-shape__list" data-reveal>
            <li><strong>Cloudflare.</strong> A token with one permission, Cache Purge.</li>
            <li><strong>CloudFront.</strong> Batched into wildcards to stay in the 1,000 free paths a month, with a counter. No AWS SDK.</li>
            <li><strong>No token yet?</strong> The CDN is told not to keep pages, so it never shows an old one.</li>
          </ul>
          <p data-reveal><a class="sh-link" :href="withBase('/guides/cloudflare')">Cloudflare</a> · <a class="sh-link" :href="withBase('/guides/cloudfront')">CloudFront →</a></p>
        </div>
      </div>
    </section>

    <!-- Pneumatic post -------------------------------------------------------- -->
    <section class="sh-city" aria-labelledby="sh-city-title">
      <div class="sh-wrap sh-city__grid">
        <figure class="sh-city__plan" data-reveal>
          <img :src="withBase('/world/hlavni-panel.jpg')" width="1018" height="632" alt="A row of brass and black tube heads with pressure gauges in Prague’s pneumatic post station" loading="lazy">
          <figcaption>Tube heads at the central station of Prague’s pneumatic post.</figcaption>
        </figure>
        <div class="sh-city__text">
          <h2 id="sh-city-title" data-reveal>Ready before anyone asks</h2>
          <p data-reveal>For more than a century, Prague’s pneumatic post sent letters through tubes under the city. Nobody walked them across town: they were sealed, sent and there. A cached page works the same way. It’s built once, ahead of time, and goes straight out to every visitor without Craft lifting a finger.</p>
          <figure data-reveal>
            <img :src="withBase('/world/hlavy-tras.jpg')" width="1011" height="752" alt="Brass control panels for each tube line, made by Mix &amp; Genest of Berlin, with coloured indicator lights" loading="lazy">
            <figcaption>A panel for each line, so the right message reached the right place.</figcaption>
          </figure>
          <figure data-reveal>
            <img :src="withBase('/world/strojovna-se-dmychadly.jpg')" width="1120" height="840" alt="The blower room: rows of blue motors that pushed air through the tubes" loading="lazy">
            <figcaption>The blower room that kept it all moving.</figcaption>
          </figure>
        </div>
      </div>
    </section>

    <!-- How it works: pinned frame ---------------------------------------------- -->
    <section class="sh-wrap sh-how" aria-labelledby="sh-how-title">
      <h2 id="sh-how-title" data-reveal>Fast in three steps</h2>
      <div class="sh-how__grid">
        <div class="sh-steps">
          <div class="sh-step" data-step="0">
            <p class="sh-step__say">Install it.</p>
            <div class="sh-call">
              <pre><code>composer require suprim/speedy
php craft plugin/install speedy</code></pre>
              <button type="button" class="sh-copy" @click="copy(installCmd, 'install')">{{ copied === 'install' ? 'Copied' : 'Copy' }}</button>
            </div>
            <p class="sh-step__note">Pages are cached from the next visit. That’s it for most sites.</p>
          </div>
          <div class="sh-step" data-step="1">
            <p class="sh-step__say">Add the server rules, if you can.</p>
            <div class="sh-call">
              <pre><code>php craft speedy/server-rules</code></pre>
              <button type="button" class="sh-copy" @click="copy(rulesCmd, 'rules')">{{ copied === 'rules' ? 'Copied' : 'Copy' }}</button>
            </div>
            <p class="sh-step__note">Optional. Pages are already cached. Paste the rules into Nginx or Apache and cached pages skip PHP too: 1 ms instead of 30.</p>
          </div>
          <div class="sh-step" data-step="2">
            <p class="sh-step__say">Add your CDN, if you have one.</p>
            <p class="sh-step__note">A Cloudflare token or CloudFront key in Settings → Plugins → Speedy Cache.</p>
          </div>
        </div>
        <div class="sh-frame" aria-hidden="true">
          <div class="sh-frame__inner">
            <pre class="sh-frame__slide sh-frame__slide--term" :class="{ 'is-on': step === 0 }"><code><span class="t-k">$</span> composer require suprim/speedy
<span class="sh-dim">  - Installing suprim/speedy (1.0.0)</span>
<span class="t-k">$</span> php craft plugin/install speedy
<span class="sh-ok">*** installed speedy successfully</span>
<span class="t-k">$</span> curl -I https://example.com/
<span class="sh-dim">x-speedy: MISS</span>
<span class="t-k">$</span> curl -I https://example.com/
<span class="sh-ok">x-speedy: HIT</span></code></pre>
            <pre class="sh-frame__slide sh-frame__slide--term sh-frame__slide--rules" :class="{ 'is-on': step === 1 }"><code><span class="t-k">$</span> php craft speedy/server-rules
<span class="sh-dim"># Serve cached pages without PHP</span>
set $speedy_page "/speedy/$host$uri/index.html";
<span class="sh-dim"># …skip visitors who are logged in, have a cart,
# send a query string or an Ajax request…</span>
if (-f $document_root$speedy_page) <span class="t-k">{</span>
  rewrite ^ $speedy_page last;
<span class="t-k">}</span>

<span class="t-k">$</span> curl -I https://example.com/
<span class="sh-ok">x-speedy: STATIC</span>   <span class="sh-dim">1 ms, no PHP</span></code></pre>
            <img class="sh-frame__slide" :class="{ 'is-on': step === 2 }" :src="withBase('/images/settings.jpg')" width="1600" height="1600" alt="" loading="lazy">
          </div>
        </div>
      </div>
    </section>

    <!-- Close ----------------------------------------------------------------- -->
    <section class="sh-close">
      <div class="sh-wrap">
        <h2>Make every page feel instant.</h2>
        <div class="sh-close__row">
          <pre class="sh-close__cmd"><code>composer require suprim/speedy</code></pre>
          <button type="button" class="sh-btn" @click="copy('composer require suprim/speedy', 'close')">{{ copied === 'close' ? 'Copied' : 'Copy command' }}</button>
          <a class="sh-link sh-link--light" :href="withBase('/getting-started/installation')">Installation guide →</a>
        </div>
      </div>
    </section>

    <!-- Footer tiles ----------------------------------------------------------- -->
    <footer class="sh-foot">
      <div class="sh-tile sh-tile--about">
        <p class="sh-tile__big">A Craft CMS plugin by suprim.</p>
        <p class="sh-tile__small">Photographs of Prague’s pneumatic post (“Hlavní panel”, “Hlavy tras” and “Strojovna se dmychadly”) by Serych at Czech Wikipedia, public domain, via Wikimedia Commons.</p>
      </div>
      <nav class="sh-tile sh-tile--nav" aria-label="Docs">
        <a :href="withBase('/getting-started/installation')">Installation</a>
        <a :href="withBase('/getting-started/server-rules')">Serving without PHP</a>
        <a :href="withBase('/guides/clearing')">How clearing works</a>
        <a :href="withBase('/guides/cloudfront')">CloudFront</a>
        <a :href="withBase('/getting-started/troubleshooting')">Troubleshooting</a>
        <a :href="withBase('/getting-started/privacy')">Privacy and data</a>
      </nav>
      <div class="sh-tile sh-tile--time">
        <p class="sh-tile__small">A cached page, served by the web server</p>
        <p class="sh-tile__clock">1 ms</p>
      </div>
      <div class="sh-tile sh-tile--mail">
        <p class="sh-tile__small">Questions and support</p>
        <a href="mailto:support@suprim.com.au">support@suprim.com.au</a>
      </div>
      <div class="sh-tile sh-tile--mark"><span>Speedy Cache</span></div>
      <a class="sh-tile sh-tile--go" :href="withBase('/getting-started/introduction')">
        <span>Read the docs</span>
        <svg width="56" height="56" viewBox="0 0 56 56" aria-hidden="true"><path d="M12 44 44 12M20 12h24v24" fill="none" stroke="currentColor" stroke-width="4"/></svg>
      </a>
    </footer>
  </div>
</template>

<style scoped>
.sh {
  --soot: #121318;
  --soot-2: #1c1e27;
  --paper: #f1efea;
  --paper-2: #e6e2d9;
  --ink: #14161f;
  --ink-soft: #4a4d57;
  --light: #f4f3ef;
  --light-soft: #b6b9c4;
  --accent: #ffd43b;
  --accent-hover: #ffe066;
  --accent-ink: #14161f;
  --amber: #8a5a00;
  --gap: 8px;
  --s-ui: 15px;
  --s-body: 17px;
  --s-lead: clamp(19px, 1.5vw, 23px);
  --s-head: clamp(28px, 2.6vw, 36px);
  --s-sect: clamp(36px, 3.6vw, 56px);
  --m: 24px;
  font-family: 'Clash Grotesk', var(--vp-font-family-base);
  color: var(--ink);
  background: var(--paper);
}
@media (min-width: 960px) { .sh { --m: 48px; } }
@media (max-width: 959px) {
  .sh-features, .sh-how { padding-top: 112px !important; padding-bottom: 112px !important; }
  .sh-city { padding: 112px 0 !important; }
  .sh-close { padding: 112px 0 96px !important; }
}

.sh-wrap { max-width: 1344px; margin: 0 auto; padding: 0 var(--m); }
.sh h1, .sh h2, .sh h3 { font-weight: 500; letter-spacing: -0.03em; line-height: 0.98; margin: 0; border: 0; padding: 0; }
.sh p { margin: 0; }
.sh a { text-decoration: none; }

.t-k { color: #ffd43b; }
.sh-dim { color: #8c90a0; }
.sh-ok { color: #8fdcab; }

.sh-btn {
  display: inline-flex; align-items: center; height: 48px; padding: 0 24px;
  border: 0; border-radius: 999px; background: var(--accent); color: var(--accent-ink);
  font: 500 var(--s-body)/1 'Clash Grotesk', var(--vp-font-family-base); cursor: pointer;
  transition: transform 0.2s cubic-bezier(0.2, 0.7, 0.2, 1), background-color 0.2s;
}
.sh-btn:hover { background: var(--accent-hover); }
.sh-btn:active { transform: scale(0.97); }
.sh-btn:focus-visible, .sh-link:focus-visible, .sh-copy:focus-visible, .sh-act:focus-visible { outline: 2px solid var(--accent); outline-offset: 3px; }
.sh-link { font-size: var(--s-body); font-weight: 500; color: var(--amber); transition: opacity 0.2s; }
.sh-link--light { color: var(--light); }
.sh-link:hover { opacity: 0.7; }

/* Hero */
.sh-hero { background: var(--soot); color: var(--light); padding: 72px 0 96px; }
.sh-hero__head { display: grid; gap: 24px; margin-bottom: 48px; }
.sh-hero h1 { font-size: clamp(44px, 6vw, 92px); max-width: 14ch; }
.sh-lead { font-size: var(--s-lead); line-height: 1.45; max-width: 36em; color: var(--light); }
.sh-actions { display: flex; flex-wrap: wrap; gap: 24px; align-items: center; }

.sh-live { display: grid; overflow: hidden; border-radius: 10px; background: #fff; color: var(--ink); font-family: var(--vp-font-family-base); }
@media (min-width: 960px) { .sh-live { grid-template-columns: 5fr 7fr; } }
.sh-live__acts { display: grid; align-content: start; gap: 8px; padding: 28px; background: #f6f5f1; }
.sh-live__site { display: grid; align-content: start; gap: 14px; padding: 28px; }
.sh-live__label { font-size: var(--s-ui); font-weight: 600; color: #5d6170; margin-bottom: 4px !important; }
.sh-act {
  display: grid; gap: 2px; text-align: left; padding: 12px 16px; border-radius: 10px; border: 0; background: #fff; color: var(--ink);
  font: inherit; cursor: pointer; transition: background-color 0.2s, transform 0.15s;
}
.sh-act b { font-size: var(--s-ui); }
.sh-act small { font-size: 13px; color: #5d6170; }
.sh-act:hover { background: #fff7d1; }
.sh-act:active { transform: scale(0.98); }
.sh-act[aria-pressed="true"] { background: var(--ink); color: var(--light); }
.sh-act[aria-pressed="true"] small { color: var(--light-soft); }
.sh-pages { margin: 0; padding: 0; list-style: none; display: grid; gap: 6px; }
.sh-pages li {
  display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 8px;
  background: #f3f4f1; font-size: var(--s-ui); transition: background-color 0.35s, color 0.35s;
}
.sh-pages__url { font-size: 15px; font-weight: 500; }
.sh-pages__state { font-size: 13px; font-weight: 600; color: #5d6170; }
.sh-pages li.is-rebuilding { background: #fff3c4; }
.sh-pages li.is-rebuilding .sh-pages__state { color: var(--amber); }
.sh-pages li.is-rebuilt { background: #e6f4ea; }
.sh-pages li.is-rebuilt .sh-pages__state { color: #1f6b45; }
.sh-live__log { min-height: 48px; padding: 12px 14px; border-radius: 8px; background: var(--soot); color: #e8e9ee; font-size: var(--s-ui); line-height: 1.5; }

/* Split: speed */
.sh-split { display: grid; }
@media (min-width: 960px) { .sh-split { grid-template-columns: 1fr 1fr; min-height: 86svh; } }
.sh-split__in, .sh-split__out { position: relative; display: grid; align-content: start; gap: 16px; padding: 96px var(--m) 140px; }
.sh-split__in { background: var(--soot-2); color: var(--light); }
.sh-split__out { background: var(--paper); }
@media (min-width: 960px) {
  .sh-split__in, .sh-split__out { padding-top: 16vh; }
  .sh-split__in { justify-items: end; }
}
.sh-times { display: grid; gap: 22px; width: min(100%, 460px); }
.sh-time { display: grid; gap: 8px; }
.sh-time__head { display: flex; justify-content: space-between; gap: 12px; font-size: var(--s-body); }
.sh-time__head b { font: 500 var(--s-head)/1 'Clash Grotesk', var(--vp-font-family-base); font-variant-numeric: tabular-nums; }
.sh-time__track { height: 10px; border-radius: 5px; background: #2b2e3b; overflow: hidden; }
.sh-time__bar { height: 10px; border-radius: 5px; background: var(--accent); }
.sh-time:first-child .sh-time__bar { background: #8c90a0; }
.sh-time small { font-size: var(--s-ui); color: var(--light-soft); }
.sh-times__note { font-size: var(--s-ui); color: var(--light-soft); line-height: 1.5; }
.sh-split__shot { margin: 0; width: min(100%, 520px); }
.sh-split__shot img { display: block; width: 100%; height: auto; border-radius: 10px; }
.sh-split__shot figcaption { margin-top: 12px; font-size: var(--s-ui); color: var(--ink-soft); }
.sh-split__title { position: absolute; bottom: 32px; font: 500 var(--s-sect)/1 'Clash Grotesk', var(--vp-font-family-base); letter-spacing: -0.03em; }
.sh-split__title--a { right: var(--m); color: var(--light); }
.sh-split__title--b { left: var(--m); color: var(--ink); }
@media (max-width: 959px) {
  .sh-split__title--a { left: var(--m); right: auto; }
  .sh-split__title--a::after { content: ' then 1 ms.'; }
  .sh-split__title--b { display: none; }
}

/* Features */
.sh-features { padding-top: 160px; padding-bottom: 160px; }
.sh-features h2 { font-size: var(--s-sect); margin-bottom: 56px; }
.sh-cells { display: grid; gap: var(--gap); }
@media (min-width: 760px) { .sh-cells { grid-template-columns: 1fr 1fr; } }
@media (min-width: 1100px) { .sh-cells { grid-template-columns: repeat(3, 1fr); } }
.sh-cell { display: grid; grid-template-rows: auto 1fr; gap: 24px; min-height: 420px; overflow: hidden; border-radius: 10px; background: var(--paper-2); }
.sh-cell__text { padding: 28px 28px 0; }
.sh-cell h3 { font-size: var(--s-lead); margin-bottom: 10px; }
.sh-cell p { font-size: var(--s-body); line-height: 1.45; color: var(--ink); }
.sh-cell img, .sh-cell__code { display: block; align-self: end; width: calc(100% - 56px); margin: 0 28px 28px; border-radius: 8px; }
.sh-cell img { height: auto; box-sizing: border-box; padding: 10px; background: #fff; }
.sh-cell__code { min-height: 170px; padding: 22px 24px; background: var(--soot); color: #e8e9ee; font: var(--s-ui)/1.6 var(--vp-font-family-mono); white-space: pre-wrap; overflow: hidden; }
.sh-cell--flip { grid-template-rows: 1fr auto; }
.sh-cell--flip > :first-child { order: 2; padding: 0 28px 28px; }
.sh-cell--flip img, .sh-cell--flip .sh-cell__code { align-self: start; margin: 28px 28px 0; }
@media (max-width: 759px) {
  .sh-cell { min-height: 0; }
  .sh-cell__text { padding: 20px 20px 0; }
  .sh-cell--flip > :first-child { padding: 0 20px 20px; }
  .sh-cell img, .sh-cell__code { width: calc(100% - 40px); margin: 0 20px 20px; }
  .sh-cell--flip img, .sh-cell--flip .sh-cell__code { margin: 20px 20px 0; }
}

/* CDN */
.sh-shape { background: var(--paper-2); padding: 160px 0; }
.sh-shape__grid { display: grid; gap: 48px; align-items: center; }
@media (min-width: 960px) { .sh-shape__grid { grid-template-columns: 6fr 6fr; gap: 64px; } }
.sh-shape__text { display: grid; gap: 24px; }
.sh-shape__text h2 { font-size: var(--s-sect); }
.sh-shape__text > p { font-size: var(--s-lead); line-height: 1.5; }
.sh-shape__list { display: grid; gap: 14px; margin: 0; padding: 0; list-style: none; font-size: var(--s-body); line-height: 1.5; }
.sh-shape__list strong { font-weight: 600; }
.sh-cdn { display: grid; gap: 20px; padding: 32px; border-radius: 10px; background: var(--soot); color: var(--light); font-family: var(--vp-font-family-base); }
.sh-cdn__seen { display: flex; align-items: center; gap: 10px; font-size: var(--s-body); }
.sh-dot { width: 10px; height: 10px; border-radius: 50%; background: var(--accent); }
.sh-cdn__list { display: grid; gap: 12px; margin: 0; padding: 0; list-style: none; }
.sh-cdn__list li { display: flex; flex-wrap: wrap; gap: 8px; }
.sh-cdn__list li span { padding: 6px 10px; border-radius: 6px; background: var(--soot-2); font-size: 15px; font-weight: 500; }
.sh-cdn__list li:last-child span { background: #3a3110; color: var(--accent); }
.sh-cdn__arrow { color: var(--light-soft); font-size: var(--s-ui); }
.sh-cdn__arrow::before { content: '↓ '; }
.sh-cdn__count { font-size: var(--s-body); }
.sh-cdn__count b { font: 500 var(--s-head)/1 'Clash Grotesk', var(--vp-font-family-base); color: var(--accent); }
.sh-cdn__count span { color: var(--light-soft); font-size: var(--s-ui); }
@media (max-width: 959px) { .sh-shape { padding: 112px 0; } }

/* Pneumatic post */
.sh-city { background: var(--paper); padding: 160px 0; }
.sh-city__grid { display: grid; gap: 48px; align-items: start; }
@media (min-width: 960px) { .sh-city__grid { grid-template-columns: 6fr 6fr; gap: 64px; } }
.sh-city__plan { margin: 0; }
@media (min-width: 960px) { .sh-city__plan { position: sticky; top: calc(var(--vp-nav-height) + 24px); } }
.sh-city__plan img, .sh-city__text img { width: 100%; height: auto; display: block; }
.sh-city figure { margin: 0; }
.sh-city figcaption { margin-top: 12px; font-size: var(--s-ui); color: var(--ink-soft); }
.sh-city__text { display: grid; gap: 32px; }
.sh-city__text h2 { font-size: var(--s-sect); }
.sh-city__text > p { font-size: var(--s-lead); line-height: 1.5; max-width: 32em; }

/* How it works */
.sh-how { padding-top: 160px; padding-bottom: 160px; }
.sh-how h2 { font-size: var(--s-sect); margin-bottom: 56px; }
.sh-how__grid { display: grid; gap: 48px; }
@media (min-width: 960px) { .sh-how__grid { grid-template-columns: 5fr 7fr; gap: 64px; } }
.sh-steps { display: grid; gap: 0; }
.sh-step { min-height: 60vh; display: grid; align-content: center; gap: 16px; }
@media (max-width: 959px) { .sh-step { min-height: 0; padding: 24px 0; } }
.sh-step__say { font: 500 var(--s-head)/1.1 'Clash Grotesk', var(--vp-font-family-base); letter-spacing: -0.02em; }
.sh-step__note { font-size: var(--s-body); line-height: 1.5; max-width: 30em; }
.sh-call { position: relative; border-radius: 8px; background: var(--soot); color: #e8e9ee; }
.sh-call pre { margin: 0; padding: 20px 88px 20px 20px; font: var(--s-ui)/1.6 var(--vp-font-family-mono); white-space: pre-wrap; overflow-wrap: anywhere; }
.sh-copy {
  position: absolute; top: 12px; right: 12px; height: 32px; padding: 0 14px; border: 0; border-radius: 999px;
  background: #2b2e3b; color: #e8e9ee; font: 500 var(--s-ui)/1 var(--vp-font-family-base); cursor: pointer;
}
.sh-copy:hover { background: #3a3e50; }
.sh-frame { display: none; }
@media (min-width: 960px) {
  .sh-frame { display: block; position: relative; }
  .sh-frame__inner { position: sticky; top: calc(var(--vp-nav-height) + 10vh); aspect-ratio: 4 / 3.3; border-radius: 10px; overflow: hidden; background: var(--paper-2); }
}
.sh-frame__slide {
  position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: top left;
  opacity: 0; transform: scale(1.02); transition: opacity 0.5s, transform 0.6s cubic-bezier(0.2, 0.7, 0.2, 1);
}
.sh-frame__slide--light { object-fit: contain; object-position: center; background: #f3f7fc; padding: 32px; box-sizing: border-box; }
.sh-frame__slide.is-on { opacity: 1; transform: none; }
.sh-frame__slide--rules { font-size: 15px; line-height: 1.7; }
@media (max-width: 759px) { .sh-frame__slide--rules { font-size: 12px; padding: 24px; } }
.sh-frame__slide--term {
  margin: 0; display: grid; align-content: center; padding: 48px; background: var(--soot); color: #e8e9ee;
  font: var(--s-body)/1.9 var(--vp-font-family-mono); white-space: pre-wrap;
}

/* Close */
.sh-close { background: var(--soot); color: var(--light); padding: 160px 0 120px; }
.sh-close h2 { font-size: clamp(44px, 7vw, 112px); max-width: 16ch; margin-bottom: 48px; }
.sh-close__row { display: flex; flex-wrap: wrap; gap: 16px 24px; align-items: center; }
.sh-close__cmd { display: inline-flex; align-items: center; height: 48px; margin: 0; padding: 0 20px; border-radius: 999px; background: var(--soot-2); color: #e8e9ee; font: var(--s-ui)/1 var(--vp-font-family-mono); }

/* Footer tiles */
.sh-foot { display: grid; gap: var(--gap); padding: var(--gap); background: var(--soot); grid-template-columns: repeat(2, 1fr); }
@media (min-width: 960px) { .sh-foot { grid-template-columns: repeat(12, 1fr); } }
.sh-tile { display: grid; align-content: space-between; gap: 16px; padding: 24px; border-radius: 10px; background: var(--soot-2); color: var(--light); min-height: 200px; }
.sh-tile__big { font-size: var(--s-lead); line-height: 1.25; }
.sh-tile__small { font-size: var(--s-ui); line-height: 1.5; color: var(--light-soft); max-width: 60ch; }
.sh-tile--about { grid-column: 1 / -1; }
.sh-tile--nav { align-content: start; gap: 8px; }
.sh-tile--nav a, .sh-tile--mail a { color: var(--light); font-size: var(--s-body); }
.sh-tile--nav a:hover, .sh-tile--mail a:hover { color: var(--accent); }
.sh-tile--mail a { font-size: var(--s-lead); overflow-wrap: anywhere; }
.sh-tile__clock { font: 500 var(--s-sect)/1 'Clash Grotesk', var(--vp-font-family-base); font-variant-numeric: tabular-nums; color: var(--accent); }
.sh-tile--mark { grid-column: 1 / -1; min-height: 220px; place-items: center; background: var(--paper); color: var(--ink); }
.sh-tile--mark span { font: 500 clamp(52px, 10vw, 168px)/0.9 'Clash Grotesk', var(--vp-font-family-base); letter-spacing: -0.04em; white-space: nowrap; }
.sh-tile--go { grid-column: 1 / -1; background: var(--accent); color: var(--accent-ink); font: 500 var(--s-head)/1.1 'Clash Grotesk', var(--vp-font-family-base); transition: background-color 0.2s; }
.sh-tile--go:hover { background: var(--accent-hover); }
.sh-tile--go svg { justify-self: end; transition: transform 0.3s cubic-bezier(0.2, 0.7, 0.2, 1); }
.sh-tile--go:hover svg { transform: translate(4px, -4px); }
@media (min-width: 960px) {
  .sh-tile { min-height: 220px; }
  .sh-tile--about { grid-column: 1 / 5; }
  .sh-tile--nav { grid-column: 5 / 8; }
  .sh-tile--time { grid-column: 8 / 10; }
  .sh-tile--mail { grid-column: 10 / 13; }
  .sh-tile--mark { grid-column: 1 / 9; min-height: 320px; }
  .sh-tile--go { grid-column: 9 / 13; min-height: 320px; }
}
@media (max-width: 520px) {
  .sh-tile--mark span { font-size: 48px; white-space: normal; text-align: center; }
}
</style>
