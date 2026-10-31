# Avapeek Web App

The Vue 3 PWA for Avapeek. Photos and comments are encrypted in the browser before upload.

For setup, see the root [README](../README.md) and [docs/development.md](../docs/development.md).

`src/crypto/` is read-only. Do not modify it. Import the existing helpers (for example `encryptString` and `decryptString` from `@/crypto/encrypt`) instead.

## Commands

Run from `frontend/`:

```sh
npm run dev            # Vite dev server
npm run type-check     # vue-tsc
npm run lint           # oxlint and eslint
npm run test:unit      # Vitest
npm run build          # type-check and production build
```
