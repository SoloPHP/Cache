import { defineConfig } from 'vitepress'

const description = 'PSR-16 cache for PHP with atomic file and Redis adapters, typed values and an explicit failure policy.'

export default defineConfig({
  title: 'Solo Cache',
  description,
  base: '/Cache/',

  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/Cache/logo.svg' }],
    ['meta', { name: 'theme-color', content: '#ef4444' }],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:title', content: 'Solo Cache' }],
    ['meta', { property: 'og:description', content: description }],
  ],

  themeConfig: {
    logo: '/logo.svg',

    nav: [
      { text: 'Guide', link: '/guide/' },
      { text: 'API', link: '/guide/api' },
      { text: 'Details', link: '/guide/details' },
      { text: 'v2.0.0', link: 'https://github.com/SoloPHP/Cache/releases' },
    ],

    sidebar: [
      {
        text: 'Solo Cache · 2.0',
        items: [
          { text: 'Getting started', link: '/guide/' },
          { text: 'API reference', link: '/guide/api' },
          { text: 'Behavior in detail', link: '/guide/details' },
          { text: 'Upgrading from 1.x', link: 'https://github.com/SoloPHP/Cache/blob/main/UPGRADE.md' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/SoloPHP/Cache' },
    ],

    footer: {
      message: 'MIT licensed.',
      copyright: 'Copyright © 2025-2026 SoloPHP',
    },

    editLink: {
      pattern: 'https://github.com/SoloPHP/Cache/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },

    search: {
      provider: 'local',
    },
  },
})
