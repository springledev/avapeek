/**
 * Web app manifest + plugin switches, kept in a plain module so that
 * `vite.config.ts` and the unit tests share one source of truth.
 */
import type { ManifestOptions } from 'vite-plugin-pwa'

/** The PWA plugin (and its service worker build) is skipped under Vitest. */
export function isPwaEnabled(env: NodeJS.ProcessEnv = process.env): boolean {
  return !env.VITEST
}

export const pwaManifest: Partial<ManifestOptions> = {
  name: 'Avapeek',
  short_name: 'Avapeek',
  description: 'End-to-end encrypted family photos',
  display: 'standalone',
  scope: '/',
  start_url: '/feed',
  background_color: '#faf6f1',
  theme_color: '#faf6f1',
  icons: [
    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png', purpose: 'any' },
    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png', purpose: 'any' },
    { src: '/icons/icon-maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
  ],
  share_target: {
    action: '/share-target',
    method: 'POST',
    enctype: 'multipart/form-data',
    params: {
      files: [{ name: 'photos', accept: ['image/*'] }],
    },
  },
} as Partial<ManifestOptions>
