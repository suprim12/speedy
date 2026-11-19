import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { defineConfig, type DefaultTheme, type HeadConfig } from 'vitepress'

// The docs are served from the domain root. Set DOCS_BASE to serve them from a subfolder.
const base = process.env.DOCS_BASE ?? '/'
// The public address, for the sitemap, canonical links, link previews and llms.txt.
const site = (process.env.DOCS_URL ?? 'https://plugins.speedy.suprim.com.au').replace(/\/$/, '')
const srcDir = fileURLToPath(new URL('..', import.meta.url))
const tagline = 'Full-page caching for Craft CMS, with no database tables and Cloudflare, CloudFront, Fastly, Bunny, KeyCDN and Varnish built in'

// A page’s address, from its file: guides/forms.md → /guides/forms, examples/index.md → /examples/.
const pageUrl = (relativePath: string) => '/' + relativePath.replace(/\.md$/, '').replace(/(^|\/)index$/, '$1')

// A page’s Markdown without its front matter.
const pageBody = (relativePath: string) => fs.readFileSync(path.join(srcDir, relativePath), 'utf8').replace(/^---\n[\s\S]*?\n---\n/, '')

// A page’s own description, or its first paragraph of text, as plain text of up to 160 characters.
function pageDescription(relativePath: string): string {
  const own = fs.readFileSync(path.join(srcDir, relativePath), 'utf8').match(/^---\n[\s\S]*?^description:\s*(.+)$/m)
  if (own) return own[1].trim().replace(/^(["'])(.*)\1$/, '$2')
  const block = pageBody(relativePath).split(/\n\s*\n/).map((b) => b.trim()).find((b) => b && !/^(#|\||```|!\[|<|-\s|\d+\.\s|:::|>)/.test(b))
  if (!block) return ''
  const text = block.replace(/!\[[^\]]*\]\([^)]*\)/g, '').replace(/\[([^\]]+)\]\([^)]*\)/g, '$1').replace(/[`*_]/g, '').replace(/\s+/g, ' ').trim()
  return text.length <= 160 ? text : text.slice(0, 157).replace(/\s+\S*$/, '') + '…'
}

const sidebar: DefaultTheme.SidebarItem[] = [
  {
    text: 'Getting Started',
    items: [
      { text: 'Introduction', link: '/getting-started/introduction' },
      { text: 'Installation', link: '/getting-started/installation' },
      { text: 'Serving Without PHP', link: '/getting-started/server-rules' },
      { text: 'Configuration', link: '/getting-started/configuration' },
      { text: 'Best Practices and Gotchas', link: '/getting-started/best-practices' },
      { text: 'How It’s Tested', link: '/getting-started/testing' },
      { text: 'Troubleshooting', link: '/getting-started/troubleshooting' },
      { text: 'Privacy and Data', link: '/getting-started/privacy' },
    ],
  },
  {
    text: 'Guides',
    items: [
      { text: 'How Clearing Works', link: '/guides/clearing' },
      { text: 'What Isn’t Cached', link: '/guides/not-cached' },
      { text: 'Forms and CSRF', link: '/guides/forms' },
      { text: 'JavaScript and Other Plugins', link: '/guides/compatibility' },
      { text: 'Switching from Another Cache', link: '/guides/switching' },
      { text: 'Cloudflare', link: '/guides/cloudflare' },
      { text: 'CloudFront', link: '/guides/cloudfront' },
      { text: 'Fastly, Bunny, KeyCDN and Varnish', link: '/guides/other-cdns' },
      { text: 'Warming and Rebuilding', link: '/guides/warming' },
      { text: 'Instant Links', link: '/guides/instant-links' },
      { text: 'Scheduled Content', link: '/guides/scheduled' },
      { text: 'The Utility Page', link: '/guides/utility' },
      { text: 'Templates', link: '/guides/templates' },
      { text: 'Console Commands', link: '/guides/console' },
      { text: 'API', link: '/guides/api' },
      { text: 'Events', link: '/guides/events' },
    ],
  },
  {
    text: 'Examples',
    items: [
      { text: 'All examples', link: '/examples/' },
      { text: 'Cart count in the header', link: '/examples/cart-count' },
      { text: 'Form in the footer', link: '/examples/footer-form' },
      { text: 'Contact form', link: '/examples/contact-form' },
      { text: 'Live data', link: '/examples/live-data' },
      { text: 'Cache status bar', link: '/examples/status-bar' },
    ],
  },
  {
    text: 'Recipes',
    items: [
      { text: 'Deploy Script', link: '/recipes/deploy' },
      { text: 'Cron Jobs', link: '/recipes/cron' },
      { text: 'Pages with Live Data', link: '/recipes/live-data' },
      { text: 'Multi-site and Several Servers', link: '/recipes/multi-site' },
    ],
  },
]

export default defineConfig({
  title: 'Speedy Cache',
  description: tagline,
  base,
  cleanUrls: true,
  sitemap: { hostname: site },

  // Each page’s own description in search results and link previews, rather than the site’s on every page.
  transformPageData(pageData) {
    if (!pageData.frontmatter.description && pageData.relativePath.endsWith('.md')) {
      const description = pageDescription(pageData.relativePath)
      if (description) {
        pageData.frontmatter.description = description
        pageData.description = description
      }
    }
  },

  // Canonical address, link previews, and on the homepage, what Speedy is for search engines.
  transformHead({ pageData, title, description }) {
    const url = site + pageUrl(pageData.relativePath)
    const head: HeadConfig[] = [
      ['link', { rel: 'canonical', href: url }],
      ['meta', { property: 'og:type', content: 'website' }],
      ['meta', { property: 'og:site_name', content: 'Speedy Cache' }],
      ['meta', { property: 'og:title', content: title }],
      ['meta', { property: 'og:description', content: description }],
      ['meta', { property: 'og:url', content: url }],
      ['meta', { property: 'og:image', content: `${site}/images/utility.jpg` }],
      ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ]
    if (pageData.relativePath === 'index.md') {
      head.push(['script', { type: 'application/ld+json' }, JSON.stringify({
        '@context': 'https://schema.org',
        '@type': 'SoftwareApplication',
        name: 'Speedy Cache',
        applicationCategory: 'DeveloperApplication',
        operatingSystem: 'Craft CMS 5',
        description: tagline,
        url: site,
        offers: { '@type': 'Offer', url: 'https://plugins.craftcms.com/speedy' },
      })])
    }
    return head
  },

  // llms.txt, llms-full.txt and a Markdown copy of each page, for AI assistants and tools that read the docs.
  buildEnd(siteConfig) {
    const out = siteConfig.outDir
    const sections = (sidebar as DefaultTheme.SidebarItem[]).map((group) => {
      const items = (group.items ?? []).filter((i) => i.link).map((i) => {
        const rel = i.link!.replace(/^\//, '').replace(/\/$/, '/index') + '.md'
        return { title: i.text!, rel, url: site + i.link!.replace(/\/$/, '/index') + '.md' }
      })
      return { title: group.text!, items }
    })
    let llms = `# Speedy Cache\n\n> ${tagline}.\n\nSpeedy Cache is a Craft CMS plugin. It saves each page as an HTML file the first time a visitor opens it, sends that copy to everyone after, and clears it when the content on it changes, using Craft’s own cache tags. It keeps no database tables, refreshes CSRF tokens on cached pages, and can serve pages straight from Nginx or Apache. Install it with \`composer require suprim/speedy\`.\n`
    let full = `# Speedy Cache\n\n> ${tagline}.\n`
    for (const section of sections) {
      llms += `\n## ${section.title}\n\n`
      for (const item of section.items) {
        const description = pageDescription(item.rel)
        llms += `- [${item.title}](${item.url})${description ? ': ' + description : ''}\n`
        const body = pageBody(item.rel)
        fs.mkdirSync(path.dirname(path.join(out, item.rel)), { recursive: true })
        fs.writeFileSync(path.join(out, item.rel), body)
        full += `\n---\n\nSource: ${site}${pageUrl(item.rel)}\n\n${body.trim()}\n`
      }
    }
    llms += `\n## Optional\n\n- [Plugin Store](https://plugins.craftcms.com/speedy): price, editions and reviews\n- [All docs in one file](${site}/llms-full.txt)\n`
    fs.writeFileSync(path.join(out, 'llms.txt'), llms)
    fs.writeFileSync(path.join(out, 'llms-full.txt'), full)
  },

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: `${base}favicon.svg` }],
    ['link', { rel: 'icon', type: 'image/png', sizes: '64x64', href: `${base}favicon.png` }],
    ['link', { rel: 'apple-touch-icon', sizes: '180x180', href: `${base}apple-touch-icon.png` }],
    ['meta', { name: 'theme-color', content: '#121318' }],
    ['link', { rel: 'preconnect', href: 'https://api.fontshare.com' }],
    ['link', { rel: 'preconnect', href: 'https://cdn.fontshare.com', crossorigin: '' }],
    ['link', { rel: 'stylesheet', href: 'https://api.fontshare.com/v2/css?f[]=clash-grotesk@400,500&display=swap' }],
  ],

  themeConfig: {
    logo: { src: '/logo.svg', width: 24, height: 24 },
    siteTitle: 'Speedy Cache',
    nav: [
      { text: 'Plugin Store', link: 'https://plugins.craftcms.com/speedy', target: '_blank' },
    ],

    sidebar,

    footer: {
      message: `A Craft CMS plugin by <a href="https://suprim.com.au" target="_blank">Suprim</a> · <a href="${base}privacy">Privacy policy</a> · <a href="${base}terms">Licence and terms</a>`,
    },

    search: {
      provider: 'local',
    },
  },
})
