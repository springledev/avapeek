import { defineConfig } from 'vitepress'

const repoUrl = 'https://github.com/springledev/avapeek'

export default defineConfig({
  title: 'Avapeek',
  description: 'End-to-end encrypted, self-hosted family photo sharing.',
  lang: 'en',
  srcDir: 'src',
  base: '/',
  cleanUrls: true,
  lastUpdated: false,
  ignoreDeadLinks: 'localhostLinks',
  sitemap: { hostname: 'https://avapeek.com/' },

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }],
    ['meta', { name: 'theme-color', content: '#faf6f1' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'Avapeek' }],
    ['meta', { property: 'og:image', content: 'https://avapeek.com/icon-192.png' }],
  ],

  markdown: {
    theme: { light: 'github-light', dark: 'github-dark' },
    languageAlias: { env: 'dotenv' },
    defaultHighlightLang: 'text',
  },

  themeConfig: {
    logo: { src: '/favicon.svg', alt: '' },

    nav: [
      { text: 'Install', link: '/docs/installation' },
      { text: 'Docs', link: '/docs/', activeMatch: '^/docs/' },
      { text: 'Security', link: '/docs/security' },
      { text: 'FAQ', link: '/docs/faq' },
      { text: 'Changelog', link: '/changelog' },
    ],

    sidebar: [
      {
        text: 'About',
        items: [
          { text: 'Overview', link: '/' },
          { text: 'Why Avapeek?', link: '/docs/why-avapeek' },
          { text: 'Hosted version', link: '/hosted' },
          { text: 'Roadmap and known issues', link: '/docs/roadmap' },
          { text: 'Changelog', link: '/changelog' },
        ],
      },
      {
        text: 'Self-hosting',
        items: [
          { text: 'Installation', link: '/docs/installation' },
          { text: 'Configuration', link: '/docs/configuration' },
          { text: 'Storage', link: '/docs/storage' },
          { text: 'FAQ and troubleshooting', link: '/docs/faq' },
        ],
      },
      {
        text: 'Security',
        items: [
          { text: 'Security model', link: '/docs/security' },
          { text: 'Reporting a vulnerability', link: '/security-policy' },
        ],
      },
      {
        text: 'Internals',
        items: [
          { text: 'Architecture', link: '/docs/architecture' },
          { text: 'Development', link: '/docs/development' },
        ],
      },
    ],

    socialLinks: [{ icon: 'github', link: repoUrl }],

    editLink: {
      // Serialized to the client, so it cannot use variables from this file.
      pattern: ({ filePath }) =>
        filePath.startsWith('docs/') && filePath !== 'docs/index.md'
          ? `https://github.com/springledev/avapeek/blob/master/${filePath}`
          : 'https://github.com/springledev/avapeek/tree/master/website/src',
      text: 'View source on GitHub',
    },

    search: { provider: 'local' },
    outline: { level: [2, 3], label: 'On this page' },

    footer: {
      message: 'Released under the AGPL-3.0-or-later license.',
      copyright: '<a href="https://springle.dev/">springle.dev</a>',
    },
  },
})
