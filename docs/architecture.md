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
3. It asks the API for an upload slot (`POST /api/photos/upload-intent` with the ciphertext sizes). The backend reserves quota under a row lock and returns presigned PUT URLs (15 minutes) for the original and the thumbnail. The browser PUTs both ciphertext blobs directly to storage, with no `Authorization` header.
4. It calls `POST /api/photos/{uuid}/complete` with the encrypted metadata and the wrapped PEK. The backend checks membership, verifies both objects exist with the reserved sizes (HEAD), then stores a `photos` row plus a `child_photo` row per child (wrapped PEK) and counts the usage. The server never sees keys or plaintext and never reads the blob bodies.

Uploads therefore bypass PHP; expired, unused slots are removed by `uploads:prune-intents` (every 15 minutes). If `UPLOADS_DIRECT=false`, `upload-intent` answers 404 and the client falls back to the multipart `POST /api/photos/upload` (up to 64 MB through PHP).

## View flow

1. The app lists photos (`/api/photos`, `/api/children/...`). Responses contain the ciphertext metadata, the encrypted LQIP and the wrapped PEK for the user's child. The "On this day" card uses `GET /api/memories?date=YYYY-MM-DD&tz=<IANA zone>`: the server matches the plaintext `taken_at` against the same month/day in earlier years, with the same membership scoping as the feed, and returns at most 48 photos with the usual fields.
2. The browser unwraps the DEK (from the KEK it derived at login), unwraps the PEK, and decrypts the LQIP for an instant blur-up placeholder.
3. For the thumbnail or original it asks the API for a **presigned GET URL** (`/api/photos/{id}` and `/api/photos/{id}/thumbnail`, both membership-checked). URLs expire after 5 minutes.
4. The browser `fetch()`es the ciphertext from that URL, decrypts it with the PEK and shows it through an object URL. Decrypted data stays in memory.

## Export flow

1. `GET /api/children/{child}/export/photos?after_id=&limit=` (keyset by photo id, up to 100 per page) returns, per photo linked to that child, the ciphertext metadata, only that child's wrapped PEK and tags, and a fresh 15 minute presigned URL. `GET /api/children/{child}/export/comments` does the same for comments (up to 200 per page). Both sit behind `child.member` (any member), re-scope the query to the child through `child_photo`, and use the `export` rate limiter (30 requests per minute per user, separate from the 60 per minute `api` limit).
2. The browser fetches each ciphertext original, decrypts it, and streams it into a store-only zip (`client-zip`, Zip64-capable) together with `manifest.json`. With the File System Access API the zip streams to disk; otherwise it is split into parts (400 MB on mobile, 1.5 GB on desktop) saved as separate downloads. Expired URLs are refreshed once through `GET /api/photos/{id}`.
3. The single-photo download uses the same helper (`src/services/export.ts`) and saves the original bytes with the decrypted original filename, or `avapeek-{date}-{id}.{ext}` when none was stored.

## Bulk actions

Photos in the feed and Recents can be multi-selected (long press on touch, or the Select button) for download, tagging, sharing to another kid, and removal. The three write endpoints sit before the `{photo}` routes and are limited to 100 ids per request; the client batches sequentially and can cancel between batches.

- `POST /api/photos/bulk-delete` runs the same detach-or-delete logic as the single delete (`PhotoRemovalService`): it removes the photo from the kids the user administers, and deletes the row, usage and blobs (after commit) only when no kid links it any more. The response buckets ids as `deleted`, `detached` and `forbidden` (visible but not removable); unknown and invisible ids are omitted.
- `POST /api/photos/bulk-tags` takes `{assignments: [{photo_id, tag_ids}]}`. The client picks, per photo, one tag per linked kid (creating missing per-kid tags first); the server authorizes each pair with `PhotoPolicy::attachTag` and attaches all of a photo's tags or none (`updated` / `skipped`).
- `POST /api/photos/share` links photos into another kid. For each photo the browser unwraps the photo key with a kid key it holds and re-wraps it under the target kid's key (`rewrapPEK`, fresh IV); only that wrap is sent. Any member of the target kid may share photos they can view into it (`PhotoPolicy::share`). Existing links are never overwritten. Tags do not carry over, since they belong to the source kid.
- Download saves the untouched original bytes with the decrypted original name, one file at a time, through the same helpers as the single-photo download.

## Presigned URLs

Both GET (downloads) and PUT (uploads) use presigned URLs.

- **Garage:** the backend signs against `AWS_ENDPOINT` (`http://garage:3900`, unreachable from browsers). `AWS_TEMPORARY_URL=<APP_URL>` rewrites the host so the URL points at your own origin; Caddy forwards `/photos/*` to Garage and sets `Host: garage:3900` so the signature validates. No bucket CORS is needed because it is same-origin.
- **External S3/B2/R2:** `AWS_TEMPORARY_URL` is empty, so URLs point directly at the provider. The browser reads and writes cross-origin, so the bucket needs a CORS rule allowing your origin with `GET`, `HEAD` and `PUT`. See [storage.md](storage.md).

## Authorization

Every photo, comment, tag and key route verifies that the caller belongs to a child linked to the photo (`child_photo` intersected with the user's children). Child-scoped routes use `child.member` and `child.admin` middleware.

Tags are per child (`GET`/`POST /api/children/{child}/tags`). The server stores a name as ciphertext (`name_encrypted` + `iv_name`, child DEK) and an opaque id; creation is throttled and capped at 200 tags per child. Photos are filtered with `?tag_ids=` and uploads attach tags with `tag_ids[]`, which must belong to the children the photo is linked to. Attaching a tag is authorized by `PhotoPolicy::attachTag`, deleting one by `TagPolicy`.

## Source layout

See [development.md](development.md#repository-layout).
