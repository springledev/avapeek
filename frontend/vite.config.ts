import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import { VitePWA } from 'vite-plugin-pwa'
import { isPwaEnabled, pwaManifest } from './pwa.config'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    ...(process.env.NODE_ENV !== 'production' ? [vueDevTools()] : []),
    // Disabled under Vitest (vitest.config.ts merges this file); no dev service worker.
    ...(isPwaEnabled()
      ? [
          VitePWA({
            strategies: 'injectManifest',
            registerType: 'prompt',
            srcDir: 'src',
            filename: 'sw.ts',
            manifestFilename: 'manifest.webmanifest',
            injectRegister: false,
            manifest: pwaManifest,
            injectManifest: {
              globPatterns: ['**/*.{js,css,html,woff2,png,ico}'],
              // Lazy HEIC decoder (3 MB) is not part of the shell; fetched on demand.
              globIgnores: ['**/heic-to-*.js'],
            },
            devOptions: { enabled: false },
          }),
        ]
      : []),
  ],
  resolve: {
    alias: [
      { find: '@', replacement: fileURLToPath(new URL('./src', import.meta.url)) },
      // The default heic-to build compiles its decoder with `new Function` inside a
      // blob: worker, which the Content-Security-Policy (no 'unsafe-eval') blocks.
      // The csp build is the same decoder and API without dynamic code generation.
      { find: /^heic-to$/, replacement: 'heic-to/csp' },
    ],
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',  // Laravel
        changeOrigin: true,
      },
      '/storage': {
        target: 'http://localhost:3900',  // Garage S3
        changeOrigin: true,
      }
    }
  }
})
