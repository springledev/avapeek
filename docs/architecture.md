# Architecture

## Components

```
                         Browser (Vue 3 SPA)
                encrypts / decrypts everything locally
                               |
                               v
                 [ your TLS reverse proxy ]   <- optional but required for HTTPS
                               |
                               v  host port 88
 +-----------------------------------------------------------------+
 |  caddy (plain HTTP :80)                                         |
 |    /api/*    --> backend:80    (FrankenPHP + Laravel, Octane)   |
 |    /photos/* --> garage:3900   (Garage profile only)            |
 |    else      --> frontend:80   (nginx serving the built SPA)    |
 |                                                                 |
 |  backend --> postgres (users, wrapped keys, ciphertext metadata)|
 |  backend --> redis    (sessions, cache, rate limits)            |
 |  backend --> S3 API   (garage:3900, or AWS / B2 / R2 ...)       |
 |  garage-init: one-shot, provisions layout, key and bucket       |
 +-----------------------------------------------------------------+
```

| Service | Image | Role |
| --- | --- | --- |
| `caddy` | `caddy:2-alpine` | Internal router. No TLS; see [installation.md](installation.md#tls-and-reverse-proxies) |
| `frontend` | built from `docker/node/Dockerfile` | nginx serving the static Vue app. The app calls the API on the **same origin** at `/api`, so one image works for every domain |
| `backend` | built from `docker/php/Dockerfile` | FrankenPHP running the Laravel API. On start it caches config and routes in production |
| `postgres` | `postgres:18-alpine` | Accounts, children, memberships, wrapped keys, photo metadata, ciphertext of comments |
| `redis` | `redis:8.2-alpine` | Sessions, cache, rate limiter |
| `garage`, `garage-init` | `dxflrs/garage` | Optional self-hosted S3. Enabled with `COMPOSE_PROFILES=garage` |

## Upload flow

1. The browser reads the file, makes a thumbnail and a small placeholder (LQIP), extracts EXIF.
2. It generates a random photo key (PEK), encrypts original, thumbnail, LQIP and EXIF with it (AES-GCM, random IV each), and wraps the PEK with the child's DEK (one wrapped copy per child it is shared with).
3. It POSTs the ciphertext and the wrapped PEK (`/api/photos/upload`, multipart, up to 64 MB) with a bearer token.
4. The backend checks membership and quota, writes the original and thumbnail ciphertext to the `s3` disk, then stores a `photos` row plus a `child_photo` row per child (wrapped PEK). The server never sees keys or plaintext.

Uploads therefore pass through PHP; only downloads go straight to storage.

## View flow

1. The app lists photos (`/api/photos`, `/api/children/...`). Responses contain the ciphertext metadata, the encrypted LQIP and the wrapped PEK for the user's child.
2. The browser unwraps the DEK (from the KEK it derived at login), unwraps the PEK, and decrypts the LQIP for an instant blur-up placeholder.
3. For the thumbnail or original it asks the API for a **presigned GET URL** (`/api/photos/{id}` and `/api/photos/{id}/thumbnail`, both membership-checked). URLs expire after 5 minutes.
4. The browser `fetch()`es the ciphertext from that URL, decrypts it with the PEK and shows it through an object URL. Decrypted data stays in memory.

## Presigned URLs

- **Garage:** the backend signs against `AWS_ENDPOINT` (`http://garage:3900`, unreachable from browsers). `AWS_TEMPORARY_URL=<APP_URL>` rewrites the host so the URL points at your own origin; Caddy forwards `/photos/*` to Garage and sets `Host: garage:3900` so the signature validates. No bucket CORS is needed because it is same-origin.
- **External S3/B2/R2:** `AWS_TEMPORARY_URL` is empty, so URLs point directly at the provider. The browser fetches cross-origin, so the bucket needs a CORS rule allowing your origin. See [storage.md](storage.md).

## Authorization

Every photo, comment, tag and key route verifies that the caller belongs to a child linked to the photo (`child_photo` intersected with the user's children). Child-scoped routes use `child.member` and `child.admin` middleware.

## PWA and service worker

The frontend is an installable PWA (`vite-plugin-pwa`, `injectManifest`, source `frontend/src/sw.ts`, registered from `src/lib/pwa-register.ts`). The build emits `sw.js` and `manifest.webmanifest` at the site root (`start_url` `/feed`, `scope` `/`).

- **Precache only.** The worker precaches static build assets (JS, CSS, fonts, icons, `index.html`) and answers navigations with the cached shell, except `/api/`, `/photos/` and `/share-target`. There is **no runtime caching**: API and storage requests always go to the network.
- The lazy `heic-to` decoder chunk (about 3 MB) is excluded from the precache, so HEIC decoding is not available offline.
- **Offline mode** is deliberately small: the app shell, an "Offline" banner, and thumbnails already in the decrypted cache. Feed metadata is not persisted.
- **Updates.** A new build installs in the background and shows an "Update available" toast; Reload activates it and removes the old precache.
- **Share target.** `POST /share-target` is intercepted by the worker, which stages the shared images in an IndexedDB inbox and redirects (303) to `/upload?shared=1`. See [security.md](security.md) for what this stores.
- **nginx rules** (`docker/nginx/frontend.conf`): `/sw.js` and `/manifest.webmanifest` are `no-cache` (the manifest is served as `application/manifest+json`); only hashed `/assets/*` are `immutable`; unhashed public files (icons, favicon, fonts) and `index.html` are `no-cache`; a direct `POST /share-target` without a worker gets a relative 303 to `/upload?shared=1` instead of a 405. Caddy needs no change. The `add_header` directive is not inherited into a location that sets its own, so each location repeats its `Cache-Control`.

## Push notifications

Optional Web Push (VAPID, `minishlink/web-push`), off unless `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` are set. No content is involved: no crypto changes, and `frontend/src/crypto` is untouched.

- **Trigger.** `Photo` and `Comment` model observers (after commit) call `PushBatcher::record(type, photo_id, actor_id)`. The first event of a 60 s window schedules one delayed `SendPushNotifications` job per type; later events only append to a cache list.
- **Resolution at send time.** The job drains the list and `PushRecipientResolver` computes recipients in a constant number of queries: members of any child linked to the photo (`child_photo` + `child_users`, the `PhotoPolicy::view` rule), minus the actor, minus users who muted that type on every shared child (`notification_preferences`; no row means on), minus users without a subscription.
- **Sending.** `WebPushService` re-validates the endpoint, pins DNS, sends `{type, url: "/recents"}` (TTL 24 h, urgency low) and maintains `failed_attempts`/`last_success_at`. 404/410 deletes the subscription.
- **Client.** `src/sw.ts` shows a generic notification from `type` (tag `avapeek-photos`/`avapeek-comments`) and focuses or opens `/recents` on click. The settings page manages the per-device subscription and per-child switches. On `pushsubscriptionchange` the worker re-subscribes but cannot authenticate (the token is never given to it), so the app re-registers the subscription at next launch.
- **Worker.** The `queue` compose service runs `queue:work redis --max-time=3600`.

## Source layout

See [development.md](development.md#repository-layout).
