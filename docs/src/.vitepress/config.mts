import { defineConfig } from 'vitepress'

// The docs are served from the domain root. Set DOCS_BASE to serve them from a subfolder.
const base = process.env.DOCS_BASE ?? '/'

export default defineConfig({
  title: 'Speedy Cache',
  description: 'Full-page caching for Craft CMS, with no database tables and Cloudflare and CloudFront built in',
  base,
  cleanUrls: true,

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

    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'Introduction', link: '/getting-started/introduction' },
          { text: 'Installation', link: '/getting-started/installation' },
          { text: 'Serving Without PHP', link: '/getting-started/server-rules' },
          { text: 'Configuration', link: '/getting-started/configuration' },
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
          { text: 'Cloudflare', link: '/guides/cloudflare' },
          { text: 'CloudFront', link: '/guides/cloudfront' },
          { text: 'Warming and Rebuilding', link: '/guides/warming' },
          { text: 'Scheduled Content', link: '/guides/scheduled' },
          { text: 'The Utility Page', link: '/guides/utility' },
          { text: 'Templates', link: '/guides/templates' },
          { text: 'Console Commands', link: '/guides/console' },
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
    ],

    footer: {
      message: 'A Craft CMS plugin by <a href="https://suprim.com.au" target="_blank">Suprim</a>',
    },

    search: {
      provider: 'local',
    },
  },
})
