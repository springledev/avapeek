# Storage

Avapeek stores only encrypted blobs. Object storage is always the Laravel `s3` disk; which S3 you use is purely environment configuration. You can switch between the bundled self-hosted Garage and AWS S3, Backblaze B2 or Cloudflare R2 without code changes.

How it works: the browser uploads encrypted blobs straight to the bucket with short-lived **presigned PUT URLs** (the backend only reserves a slot, signs the URLs and later verifies the objects exist; set `UPLOADS_DIRECT=false` to stream uploads through PHP instead). Downloads use short-lived **presigned GET URLs** that the browser fetches directly from the storage provider. Two consequences:

- The bucket must be **private**. Never enable public read access; presigned URLs are the only access path.
- The bucket needs a **CORS rule**, because the browser reads (GET) and writes (PUT) the presigned URLs from your app origin. The rule must allow `GET`, `HEAD` and `PUT`, and `AllowedHeaders` must cover `Content-Type` and `Cache-Control` (the examples below use `*`). Garage behind the bundled Caddy is same-origin and needs no CORS.

All variables below go in `backend/.env` (and `COMPOSE_PROFILES` in the root `.env`).

## Choosing a provider

| Setting | Self-hosted Garage | AWS S3 | Backblaze B2 | Cloudflare R2 |
| --- | --- | --- | --- | --- |
| `COMPOSE_PROFILES` | `garage` | unset | unset | unset |
| `AWS_ENDPOINT` | `http://garage:3900` | unset | `https://s3.<region>.backblazeb2.com` | `https://<account_id>.r2.cloudflarestorage.com` |
| `AWS_DEFAULT_REGION` | `garage` | e.g. `eu-west-2` | e.g. `us-west-004` | `auto` |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` | `false` | `false` | `false` |
| `AWS_TEMPORARY_URL` | your app origin | unset | unset | unset |

`AWS_TEMPORARY_URL` is only for the Garage setup, where Caddy proxies `/photos/*` to Garage and presigned URLs are rewritten to the app origin. Leave it unset for external providers so presigned URLs point straight at the provider.

## Self-hosted Garage

Requires **Docker Compose 2.20 or newer** (the backend uses `depends_on ... required: false` so it starts without Garage when the profile is off).

Root `.env`:

```env
COMPOSE_PROFILES=garage
GARAGE_RPC_SECRET=...        # openssl rand -hex 32
S3_ACCESS_KEY_ID=...         # echo "GK$(openssl rand -hex 12)"
S3_SECRET_ACCESS_KEY=...     # openssl rand -hex 32
```

`backend/.env`:

```env
AWS_ACCESS_KEY_ID=<same as S3_ACCESS_KEY_ID>
AWS_SECRET_ACCESS_KEY=<same as S3_SECRET_ACCESS_KEY>
AWS_DEFAULT_REGION=garage
AWS_BUCKET=photos
AWS_ENDPOINT=http://garage:3900
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_TEMPORARY_URL=https://your-domain.com   # same as APP_URL
# Derive the quota from free disk space (compose mounts garage_data here, read-only)
STORAGE_VOLUME_PATH=/garage-data
```

Browser requests go to your own origin, so no bucket CORS is needed.

## AWS S3

```env
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-west-2
AWS_BUCKET=your-bucket
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_TEMPORARY_URL=
STORAGE_VOLUME_PATH=          # unset: do not derive the quota from the (empty) Garage volume
```

Leave `AWS_ENDPOINT` and `AWS_TEMPORARY_URL` unset. Use an IAM user limited to this bucket (`s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`, `s3:ListBucket`), and keep "Block all public access" on.

CORS (Bucket > Permissions > CORS):

```json
[
  {
    "AllowedOrigins": ["https://your-domain.com"],
    "AllowedMethods": ["GET", "HEAD", "PUT"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["Content-Length", "ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

## Backblaze B2

```env
AWS_ACCESS_KEY_ID=<application keyID>
AWS_SECRET_ACCESS_KEY=<applicationKey>
AWS_DEFAULT_REGION=us-west-004
AWS_BUCKET=your-bucket
AWS_ENDPOINT=https://s3.us-west-004.backblazeb2.com
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_TEMPORARY_URL=
STORAGE_VOLUME_PATH=          # unset: do not derive the quota from the (empty) Garage volume
```

Create an application key scoped to the bucket (read and write) and make the bucket **Private**. The region is the part of your bucket's S3 endpoint between `s3.` and `.backblazeb2.com`.

CORS via the S3 API (needs the AWS CLI; use a key that may manage the bucket, e.g. a master or bucket-admin key). Save as `cors.json`:

```json
{
  "CORSRules": [
    {
      "AllowedOrigins": ["https://your-domain.com"],
      "AllowedMethods": ["GET", "HEAD", "PUT"],
      "AllowedHeaders": ["*"],
      "ExposeHeaders": ["Content-Length", "ETag"],
      "MaxAgeSeconds": 3600
    }
  ]
}
```

```sh
AWS_ACCESS_KEY_ID=<keyID> AWS_SECRET_ACCESS_KEY=<applicationKey> \
aws s3api put-bucket-cors \
  --endpoint-url https://s3.us-west-004.backblazeb2.com \
  --bucket your-bucket \
  --cors-configuration file://cors.json
```

## Cloudflare R2

```env
AWS_ACCESS_KEY_ID=<R2 access key id>
AWS_SECRET_ACCESS_KEY=<R2 secret access key>
AWS_DEFAULT_REGION=auto
AWS_BUCKET=your-bucket
AWS_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_TEMPORARY_URL=
STORAGE_VOLUME_PATH=          # unset: do not derive the quota from the (empty) Garage volume
```

Create an API token with "Object Read & Write" scoped to the bucket. Do not enable the public `r2.dev` URL or a custom public domain.

CORS (Bucket > Settings > CORS policy):

```json
[
  {
    "AllowedOrigins": ["https://your-domain.com"],
    "AllowedMethods": ["GET", "HEAD", "PUT"],
    "AllowedHeaders": ["*"],
    "ExposeHeaders": ["Content-Length", "ETag"],
    "MaxAgeSeconds": 3600
  }
]
```

## Quota

Set `STORAGE_LIMIT_BYTES` for a fixed limit. If it is unset and `STORAGE_VOLUME_PATH` points at an existing directory, the limit is 90% of the free space there (for Garage, use `STORAGE_VOLUME_PATH=/garage-data`, which compose mounts read-only into the backend). With neither set the limit is **unknown**: uploads are not capped by Avapeek and the UI shows usage without a limit. That is intended only for external providers, which enforce their own quota. If you self-host Garage, always set `STORAGE_VOLUME_PATH` or `STORAGE_LIMIT_BYTES`, otherwise uploads are uncapped and can fill the disk.

## Database backups

The scheduler uploads nightly database dumps under `backups/db/` (the photos live under `photos/`; `storage:reconcile` never touches `backups/`). By default they go into the **same bucket** as the photos, so one incident (bucket deleted, provider account lost, host disk failed) loses photos and backups together. Point `BACKUP_AWS_*` at a different bucket, ideally at a different provider (see [configuration](configuration.md#database-backups)). If you prefer provider-side expiry, give the backup key write/list-only permissions, add a lifecycle rule on `backups/db/`, and set `BACKUP_RETENTION_DAYS=0`, so a compromised app cannot erase the history.

With the bundled Garage, backups consume the same disk that `STORAGE_VOLUME_PATH` measures (they shrink the derived quota). They are not counted in the usage shown in the app. Check the backup destination with `php artisan storage:check --backup`.

## Verify your setup

```sh
docker compose exec backend php artisan storage:check
```

The command writes a tiny object, generates a presigned URL, fetches it over HTTP with `Origin` set to `FRONTEND_URL` (or `APP_URL`), checks the content and the `Access-Control-Allow-Origin` header, then deletes the object. It then repeats the exercise for uploads: it generates a presigned PUT URL, sends a CORS preflight (`OPTIONS` with `Access-Control-Request-Method: PUT`, external providers only), PUTs a tiny body with the signed `Content-Type` and `Cache-Control` headers, verifies the object, and deletes it. A PUT failure fails the command when `UPLOADS_DIRECT=true` (the default) and only warns otherwise. Failures come with a hint: wrong secret or region/path-style (`SignatureDoesNotMatch`), unknown key, missing bucket, unreachable endpoint, missing CORS, or a key lacking `s3:PutObject`.

### What `storage:check` does not cover

With Garage (`AWS_TEMPORARY_URL` set), the command reads and writes via `AWS_ENDPOINT` directly, so it does not exercise the browser-facing route (your reverse proxy forwarding `/photos/*` to Garage). If you run your own reverse proxy in front of the stack, make sure it allows request bodies of at least 50 MB on `/photos/*`, since uploads are now PUT through it to Garage. Test that from your own machine by presigning in the container and requesting the public URL:

```sh
docker compose exec backend php artisan tinker --execute 'echo app(App\Services\StorageService::class)->presignedUrl("does-not-exist", 5);'
curl -I "<printed url>"
```

A `404` with an S3 `NoSuchKey` body means routing and signature are fine; `403 SignatureDoesNotMatch` means the proxy Host header or `AWS_TEMPORARY_URL` is wrong.

The bundled Caddy's Content-Security-Policy adds the storage origin to `connect-src` automatically (the backend derives it from these settings on start). After changing `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_DEFAULT_REGION` or `AWS_USE_PATH_STYLE_ENDPOINT`, restart the backend so the origin is re-derived. If photos stop loading, look for "Refused to connect … Content Security Policy" in the browser console and check `php artisan storage:csp-origin`.
