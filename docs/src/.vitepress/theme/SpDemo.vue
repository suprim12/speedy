<script setup>
// A short, looping demo of the utility page, played like a screen recording: a pointer types, clicks and opens
// things. Its words are the plugin’s own. Plays only while on screen; with reduced motion it shows the end.
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'

const props = defineProps({
  kind: { type: String, required: true }, // why, performance or coverage
})
// After each play-through, so a page can move on to another demo.
const emit = defineEmits(['done'])

const labels = {
  why: 'Checking two pages with “Why isn’t this page cached?”: one sets a cookie, the other is cached and sent by the web server.',
  performance: 'The Performance tab: the slowest pages, then a query cleared by any change, opened to show its code, and the list opened with Show more.',
  coverage: 'Coverage: each section’s cached pages, then Build the pages not cached filling every section.',
}

const frame = ref(null)
const s = reactive({
  pointer: { x: 40, y: 40, down: false, shown: false },
  // why
  typed: '',
  checking: false,
  result: null,
  // performance
  slow: 0,
  broad: 0,
  code: false,
  more: false,
  // coverage
  built: false,
  building: false,
})

const slowPages = [
  { url: 'example.com/news', ms: 1840 },
  { url: 'example.com/shop/shoes', ms: 1215 },
  { url: 'example.com/', ms: 640 },
]
const broadRows = [
  { type: 'Entries', at: '_layout, line 12', pages: 248 },
  { type: 'Assets', at: 'news/_entry, line 31', pages: 154 },
  { type: 'Categories', at: '_partials/footer, line 8', pages: 96 },
  { type: 'Entries', at: 'shop/_product, line 54', pages: 41 },
  { type: 'Entries', at: 'events/_index, line 19', pages: 12 },
]
const code = [
  [10, '<nav class="menu">'],
  [11, '  <ul>'],
  [12, '    {% for page in craft.entries.level(1).all() %}'],
  [13, '      <li><a href="{{ page.url }}">{{ page.title }}</a></li>'],
  [14, '    {% endfor %}'],
]
const sections = [
  { name: 'Products', cached: 212, total: 290 },
  { name: 'Events', cached: 21, total: 48 },
  { name: 'Pages', cached: 30, total: 38 },
  { name: 'News', cached: 149, total: 154 },
]
const counts = reactive(sections.map((x) => x.cached))

let visible = false
let alive = true
let observer = null
const wait = (ms) =>
  new Promise((resolve) => {
    const start = performance.now()
    const tick = () => {
      if (!alive) return
      // Time only passes while the demo is on screen.
      if (!visible || document.hidden) return setTimeout(tick, 200)
      performance.now() - start >= ms ? resolve() : setTimeout(tick, Math.min(100, ms))
    }
    tick()
  })

function point(selector) {
  const target = frame.value?.querySelector(selector)
  if (!target) return
  const a = frame.value.getBoundingClientRect()
  const b = target.getBoundingClientRect()
  s.pointer.shown = true
  s.pointer.x = b.left - a.left + Math.min(b.width / 2, 60)
  s.pointer.y = b.top - a.top + b.height / 2
}
async function click(selector) {
  point(selector)
  await wait(700)
  s.pointer.down = true
  await wait(160)
  s.pointer.down = false
}
async function type(text) {
  s.typed = ''
  for (const ch of text) {
    s.typed += ch
    await wait(55)
  }
}

const scenes = {
  async why() {
    for (const page of [
      {
        url: '/shop/cart-offers',
        result: {
          ok: false,
          answer: 'Not cached: sets a cookie.',
          why: 'The page sets `cart_token`, so each visitor gets their own copy.',
          fix: 'Find what sets it, such as a plugin or a `{% header %}` tag. Cookies a visitor chooses, such as a consent banner’s, are best set by JavaScript.',
          visits: 'First visit: X-Speedy: SKIP sets a cookie, status 200, 388 ms',
        },
      },
      {
        url: '/news/summer-sale',
        result: {
          ok: true,
          answer: 'Cached: it was built on the first visit and sent from the cache on the second.',
          why: 'Sent by the web server, without PHP.',
          visits: 'First visit: X-Speedy: MISS, status 200, 412 ms · Second visit: X-Speedy: STATIC, status 200, 1 ms',
        },
      },
    ]) {
      s.result = null
      s.typed = ''
      point('[data-at=input]')
      await wait(800)
      await type(page.url)
      await click('[data-at=check]')
      s.checking = true
      await wait(1100)
      s.checking = false
      s.result = page.result
      point('[data-at=result]')
      await wait(4200)
    }
  },
  async performance() {
    s.slow = 0
    s.broad = 0
    s.code = false
    s.more = false
    s.pointer.shown = false
    for (let i = 1; i <= slowPages.length; i++) {
      await wait(350)
      s.slow = i
    }
    await wait(600)
    for (let i = 1; i <= 3; i++) {
      await wait(250)
      s.broad = i
    }
    await wait(900)
    await click('[data-at=place]')
    s.code = true
    await wait(3200)
    await click('[data-at=place]')
    s.code = false
    await wait(500)
    await click('[data-at=toggle]')
    s.more = true
    await wait(2600)
    await click('[data-at=toggle]')
    s.more = false
    await wait(1800)
  },
  async coverage() {
    s.built = false
    s.building = false
    sections.forEach((x, i) => (counts[i] = x.cached))
    s.pointer.shown = false
    await wait(1600)
    await click('[data-at=build]')
    s.building = true
    // Count each section up to its total, as the queue builds the pages.
    for (let step = 1; step <= 20; step++) {
      await wait(110)
      sections.forEach((x, i) => (counts[i] = Math.round(x.cached + ((x.total - x.cached) * step) / 20)))
    }
    s.building = false
    s.built = true
    await wait(4000)
  },
}

function finalState() {
  if (props.kind === 'why') {
    s.typed = '/news/summer-sale'
    s.result = {
      ok: true,
      answer: 'Cached: it was built on the first visit and sent from the cache on the second.',
      why: 'Sent by the web server, without PHP.',
      visits: 'First visit: X-Speedy: MISS, status 200, 412 ms · Second visit: X-Speedy: STATIC, status 200, 1 ms',
    }
  } else if (props.kind === 'performance') {
    s.slow = slowPages.length
    s.broad = 3
    s.code = true
  }
}

const pct = (i) => Math.floor((counts[i] / sections[i].total) * 100)
const total = () => sections.reduce((n, x) => n + x.total, 0)
const cachedTotal = () => counts.reduce((n, x) => n + x, 0)
const notCached = () => total() - sections.reduce((n, x) => n + x.cached, 0)
// `code` in the plugin’s messages, shown as code.
const parts = (text) => text.split(/(`[^`]+`)/).filter(Boolean).map((t) => (t.startsWith('`') ? { code: t.slice(1, -1) } : { text: t }))

onMounted(async () => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    finalState()
    return
  }
  observer = new IntersectionObserver(([entry]) => (visible = entry.isIntersecting), { threshold: 0.35 })
  observer.observe(frame.value)
  while (alive) {
    await scenes[props.kind]()
    if (alive) emit('done')
    await wait(600)
  }
})
onBeforeUnmount(() => {
  alive = false
  observer?.disconnect()
})
</script>

<template>
  <figure class="sd" ref="frame" role="img" :aria-label="labels[kind]">
    <div class="sd__bar" aria-hidden="true"><i></i><i></i><i></i><span>Utilities → Speedy Cache</span></div>

    <div class="sd__body" aria-hidden="true">
      <!-- Why isn’t this page cached? -->
      <template v-if="kind === 'why'">
        <div class="sd__card">
          <div class="sd__h">Why isn’t this page cached?</div>
          <p class="sd__light">Opens it as a visitor would, and says what to change.</p>
          <div class="sd__row">
            <div class="sd__input" data-at="input">
              <span v-if="s.typed">{{ s.typed }}</span><span v-else class="sd__ph">https://… or /news</span><b class="sd__caret"></b>
            </div>
            <span class="sd__btn" data-at="check">{{ s.checking ? 'Checking…' : 'Check' }}</span>
          </div>
        </div>
        <transition name="sd-pop">
          <div v-if="s.result" class="sd__result" :class="s.result.ok ? 'is-ok' : 'is-warn'" data-at="result">
            <span class="sd__dot"></span>
            <div>
              <p><strong><template v-for="(p, i) in parts(s.result.answer)" :key="i"><code v-if="p.code">{{ p.code }}</code><template v-else>{{ p.text }}</template></template></strong></p>
              <p><template v-for="(p, i) in parts(s.result.why)" :key="i"><code v-if="p.code">{{ p.code }}</code><template v-else>{{ p.text }}</template></template></p>
              <p v-if="s.result.fix"><strong>What to do:</strong> <template v-for="(p, i) in parts(s.result.fix)" :key="i"><code v-if="p.code">{{ p.code }}</code><template v-else>{{ p.text }}</template></template></p>
              <p class="sd__light sd__small">{{ s.result.visits }}</p>
            </div>
          </div>
        </transition>
      </template>

      <!-- Performance -->
      <template v-else-if="kind === 'performance'">
        <div class="sd__tabs"><span>Pages</span><span>Recent clears</span><span class="is-on">Performance</span><span>Setup</span></div>
        <div class="sd__h">Slowest pages</div>
        <table class="sd__table">
          <thead><tr><th>Page</th><th>Built in</th><th class="sd__wide">Queries</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in slowPages" :key="row.url" :class="{ 'is-in': i < s.slow }">
              <td><span class="sd__link">{{ row.url }}</span></td>
              <td><strong>{{ row.ms >= 1000 ? (row.ms / 1000).toFixed(1) + ' s' : row.ms + ' ms' }}</strong><span class="sd__bar-ms" :style="{ width: (row.ms / 1840) * 100 + '%' }"></span></td>
              <td class="sd__wide">{{ 40 + i * 23 }}</td>
            </tr>
          </tbody>
        </table>

        <div class="sd__h">Cleared by any change</div>
        <table class="sd__table sd__table--fixed">
          <thead><tr><th>Query</th><th class="sd__on">On</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in broadRows" :key="row.at" v-show="i < 3 || s.more" :class="{ 'is-in': i < s.broad || (i >= 3 && s.more) }">
              <td>
                <div>Any of: {{ row.type }}</div>
                <div class="sd__place" :data-at="i === 0 ? 'place' : null"><span class="sd__tri" :class="{ 'is-open': i === 0 && s.code }">▶</span> {{ row.at }}</div>
                <transition name="sd-open">
                  <pre v-if="i === 0 && s.code" class="sd__code"><template v-for="[n, line] in code" :key="n"><span class="sd__ln" :class="{ hit: n === 12 }">{{ n }}</span><span :class="{ hit: n === 12 }">{{ line }}</span></template></pre>
                </transition>
              </td>
              <td class="sd__nowrap"><span class="sd__link">{{ row.pages }} pages</span></td>
            </tr>
          </tbody>
        </table>
        <span class="sd__btn sd__btn--small" data-at="toggle" :class="{ 'is-in': s.broad >= 3 }">{{ s.more ? 'Show less' : 'Show 2 more' }}</span>
      </template>

      <!-- Coverage -->
      <template v-else>
        <div class="sd__tabs"><span class="is-on">Pages</span><span>Recent clears</span><span>Performance</span><span>Setup</span></div>
        <div class="sd__h">Coverage <span class="sd__big">{{ Math.floor((cachedTotal() / total()) * 100) }}%</span></div>
        <p class="sd__light">{{ cachedTotal() }} of {{ total() }} content pages</p>
        <table class="sd__table">
          <thead><tr><th>Section</th><th>Cached</th></tr></thead>
          <tbody>
            <tr v-for="(row, i) in sections" :key="row.name" class="is-in">
              <td>{{ row.name }}</td>
              <td>
                <div class="sd__meter"><span :style="{ width: pct(i) + '%' }" :class="{ 'is-full': counts[i] === row.total }"></span></div>
                <span class="sd__small">{{ counts[i] }} of {{ row.total }}</span>
              </td>
            </tr>
          </tbody>
        </table>
        <span class="sd__btn" :class="{ 'sd__btn--submit': !s.built }" data-at="build">
          {{ s.built ? 'Every page cached' : s.building ? 'Building…' : 'Build the ' + notCached() + ' pages not cached' }}
        </span>
      </template>
    </div>

    <span class="sd__pointer" :class="{ 'is-down': s.pointer.down, 'is-shown': s.pointer.shown }" :style="{ transform: `translate(${s.pointer.x}px, ${s.pointer.y}px)` }" aria-hidden="true">
      <svg viewBox="0 0 16 22" width="16" height="22"><path d="M1 1v17l4.5-4.2 3 6.7 2.6-1.2-3-6.6H14z" fill="#111" stroke="#fff" stroke-width="1.4" stroke-linejoin="round"/></svg>
    </span>
  </figure>
</template>

<style scoped>
/* A light control panel in both themes, as on a real site. */
.sd {
  --cp-bg: #f3f7fc;
  --cp-text: #3f4d5a;
  --cp-light: #606d7b;
  --cp-link: #3253c8;
  --cp-line: #e3e8ef;
  --cp-btn: #e4e9f0;
  --cp-red: #d93a2a;
  --cp-ok: #1f9d55;
  --cp-warn: #e7a720;
  position: relative;
  margin: 20px 0 28px;
  border: 1px solid var(--vp-c-divider);
  border-radius: 12px;
  overflow: hidden;
  background: var(--cp-bg);
  color: var(--cp-text);
  font: 13px/1.5 system-ui, -apple-system, 'Segoe UI', sans-serif;
  box-shadow: 0 10px 30px -12px rgb(0 0 0 / 0.25);
  user-select: none;
}
.sd__bar { display: flex; align-items: center; gap: 6px; padding: 8px 12px; background: #e6ebf2; border-bottom: 1px solid var(--cp-line); color: var(--cp-light); font-size: 12px; }
.sd__bar i { width: 10px; height: 10px; border-radius: 50%; background: #c9d1dc; }
.sd__bar span { margin-left: 8px; }
.sd__body { padding: 18px; min-height: 300px; }
.sd__card { background: #fff; border: 1px solid var(--cp-line); border-radius: 8px; padding: 14px 16px; }
.sd__h { font-weight: 600; font-size: 14px; margin: 4px 0 6px; display: flex; align-items: baseline; gap: 10px; }
.sd__table + .sd__h { margin-top: 16px; }
.sd__big { font-size: 22px; font-weight: 700; }
.sd .sd__light { color: var(--cp-light); margin: 0 0 10px; }
.sd__small { font-size: 12px; }
.sd__row { display: flex; gap: 8px; flex-wrap: wrap; }
.sd__input { flex: 1 1 180px; border: 1px solid #cdd8e4; border-radius: 5px; padding: 6px 10px; background: #fff; white-space: nowrap; overflow: hidden; }
.sd__ph { color: #9aa5b1; }
.sd__caret { display: inline-block; width: 1px; height: 14px; margin-left: 1px; vertical-align: -2px; background: var(--cp-text); animation: sd-blink 1s steps(1) infinite; }
.sd__btn { display: inline-block; padding: 6px 14px; border-radius: 5px; background: var(--cp-btn); font-weight: 500; transition: opacity 0.3s; }
.sd__btn--small { margin-top: 8px; padding: 3px 10px; font-size: 12px; opacity: 0; }
.sd__btn--small.is-in { opacity: 1; }
.sd__nowrap { white-space: nowrap; }
.sd__table--fixed { table-layout: fixed; }
.sd__on { width: 92px; }
[data-at=build] { margin-top: 14px; }
.sd__btn--submit { background: var(--cp-red); color: #fff; }
.sd__result { display: flex; gap: 10px; margin-top: 14px; padding: 12px 14px; border-radius: 8px; background: #fff; border: 1px solid var(--cp-line); }
.sd p { margin: 0 0 4px; line-height: 1.5; }
.sd code { color: inherit; font-size: 12px; }
.sd__result code, .sd__code { font: 12px/1.6 ui-monospace, SFMono-Regular, Menlo, monospace; }
.sd__result code { background: #eef2f7 !important; padding: 1px 4px; border-radius: 3px; }
.sd__dot { flex: none; width: 8px; height: 8px; margin-top: 6px; border-radius: 50%; }
.is-ok .sd__dot { background: var(--cp-ok); }
.is-warn .sd__dot { background: var(--cp-warn); }
.sd__tabs { display: flex; gap: 18px; border-bottom: 1px solid var(--cp-line); margin: -4px 0 14px; overflow: hidden; }
.sd__tabs span { padding: 6px 0; color: var(--cp-light); white-space: nowrap; }
.sd__tabs .is-on { color: var(--cp-text); box-shadow: inset 0 -2px var(--cp-text); }
.sd__table { display: table; width: 100%; margin: 0; border-collapse: collapse; background: #fff; border-radius: 6px; overflow: hidden; }
.sd__table th, .sd__table td { border: 0; border-top: 1px solid var(--cp-line); padding: 7px 10px; text-align: left; vertical-align: top; background: none; }
.sd__table tr { background: none; border: 0; }
.sd__table th { background: #eef2f7; font-weight: 600; border-top: 0; }
.sd__table tbody tr { opacity: 0; transform: translateY(4px); transition: opacity 0.35s, transform 0.35s; }
.sd__table tbody tr.is-in { opacity: 1; transform: none; }
.sd__link { color: var(--cp-link); }
.sd__bar-ms { display: block; height: 3px; margin-top: 3px; border-radius: 2px; background: linear-gradient(90deg, #f0b429, var(--cp-red)); }
.sd__place { color: var(--cp-light); cursor: default; }
.sd__tri { display: inline-block; font-size: 9px; transition: transform 0.2s; }
.sd__tri.is-open { transform: rotate(90deg); }
.sd__code { display: grid; grid-template-columns: auto 1fr; column-gap: 12px; margin: 6px 0 2px; padding: 8px 10px; border-radius: 6px; background: #f3f6fa; color: #334; overflow: hidden; white-space: pre; overflow-x: auto; }
.sd__ln { color: #9aa5b1; text-align: right; }
.sd__code .hit { background: #fff3c4; }
.sd__meter { height: 6px; border-radius: 3px; background: #e6ebf2; overflow: hidden; margin: 6px 0 2px; }
.sd__meter span { display: block; height: 100%; background: var(--cp-link); transition: width 0.2s; }
.sd__meter span.is-full { background: var(--cp-ok); }
.sd__pointer { position: absolute; left: 0; top: 0; pointer-events: none; opacity: 0; transition: transform 0.65s cubic-bezier(0.3, 0.7, 0.3, 1), opacity 0.3s; }
.sd__pointer.is-shown { opacity: 1; }
.sd__pointer svg { display: block; transition: transform 0.12s; filter: drop-shadow(0 1px 1px rgb(0 0 0 / 0.3)); }
.sd__pointer.is-down svg { transform: scale(0.82); }
.sd-pop-enter-active { transition: opacity 0.35s, transform 0.35s; }
.sd-pop-enter-from { opacity: 0; transform: translateY(6px); }
.sd-open-enter-active { transition: opacity 0.3s; }
.sd-open-enter-from { opacity: 0; }
@keyframes sd-blink { 50% { opacity: 0; } }
@media (max-width: 560px) {
  .sd__body { padding: 12px; }
  .sd__wide { display: none; }
  .sd__code { font-size: 11px; }
}
</style>
