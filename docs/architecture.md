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

## Source layout

See [development.md](development.md#repository-layout).
