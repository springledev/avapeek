# Configuration

Avapeek is configured through two env files, both created by `./up.sh`:

- **Root `.env`** is read by `docker compose` itself (interpolated into `docker-compose.prod.yml`).
- **`backend/.env`** is loaded into the Laravel containers via `env_file`.

Some values appear in both and must match. After editing `backend/.env`, recreate the backend with `docker compose -f docker-compose.prod.yml up -d` (config is cached at container start). Required means the stack will not work without a correct value; `up.sh` fills these for you.

## Root `.env`

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `DB_DATABASE` | `avapeek` | yes | PostgreSQL database name. Must match `backend/.env` |
| `DB_USERNAME` | `avapeek` | yes | PostgreSQL user. Must match `backend/.env` |
| `DB_PASSWORD` | generated | yes | PostgreSQL password. Must match `backend/.env` |
| `REDIS_PASSWORD` | generated | yes | Redis password. Must match `backend/.env` |
| `COMPOSE_PROFILES` | `garage` (set by `up.sh --storage garage`) | no | `garage` starts the bundled Garage S3 and its init container. Leave empty for an external S3 provider. Needs Compose >= 2.20 |
| `GARAGE_RPC_SECRET` | generated | with `garage` | 32-byte hex secret for Garage node RPC (`openssl rand -hex 32`) |
| `S3_ACCESS_KEY_ID` | generated | with `garage` | Garage key id, `GK` + 24 hex. Imported into Garage by `garage-init`. Must equal `AWS_ACCESS_KEY_ID` |
| `S3_SECRET_ACCESS_KEY` | generated | with `garage` | 64 hex chars. Must equal `AWS_SECRET_ACCESS_KEY` |
| `GARAGE_ADMIN_TOKEN` | falls back to `GARAGE_RPC_SECRET` | no | Garage admin API token |
| `AVAPEEK_VERSION` | `latest` | no | Image tag pulled by the release compose file (`./up.sh` without `--build`). Not in the example file by default (commented) |

## `backend/.env`

### Application

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `APP_NAME` | `Avapeek` | no | Shown in the TOTP authenticator app |
| `APP_ENV` | `production` | yes | Must stay `production`; the entrypoint only caches config/routes in production |
| `APP_KEY` | generated | yes | `base64:` + 32 random bytes. Encrypts sessions and cookies. Changing it signs everyone out; it does **not** touch photo encryption (that is client-side) |
| `APP_DEBUG` | `false` | no | Never enable in production |
| `APP_URL` | `https://your-domain.com` | yes | Public URL users type in. Used to build invite links and as the base for presigned URLs with Garage |
| `FRONTEND_URL` | same as `APP_URL` | yes | Origin the web app is served from. Passkeys (WebAuthn) bind to it, CORS allows it, and `storage:check` uses it as `Origin` |
| `APP_LOCALE`, `APP_FALLBACK_LOCALE`, `APP_FAKER_LOCALE` | `en`, `en`, `en_US` | no | Laravel locale settings |
| `APP_MAINTENANCE_DRIVER` | `file` | no | Laravel maintenance mode driver |
| `BCRYPT_ROUNDS` | `12` | no | Cost of the server-side login password hash |
| `LOG_CHANNEL` | `stderr` | no | Logs go to container output (`docker compose logs backend`) |
| `LOG_LEVEL` | `warning` | no | Minimum log level |

### Database and Redis

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `DB_CONNECTION` | `pgsql` | yes | Leave as `pgsql` |
| `DB_HOST` | `postgres` | yes | Compose service name |
| `DB_PORT` | `5432` | no | |
| `DB_DATABASE`, `DB_USERNAME` | `avapeek` | yes | Must match root `.env` |
| `DB_PASSWORD` | generated | yes | Must match root `.env` |
| `REDIS_CLIENT` | `phpredis` | no | |
| `REDIS_HOST` | `redis` | yes | Compose service name |
| `REDIS_PORT` | `6379` | no | |
| `REDIS_PASSWORD` | generated | yes | Must match root `.env` |

### Sessions, cache, queue

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `SESSION_DRIVER` | `redis` | no | |
| `SESSION_LIFETIME` | `120` | no | Minutes |
| `SESSION_ENCRYPT` | `true` | no | |
| `SESSION_PATH`, `SESSION_DOMAIN` | `/`, `null` | no | |
| `CACHE_STORE` | `redis` | no | |
| `QUEUE_CONNECTION` | `redis` | no | The core app currently dispatches no queued jobs |
| `BROADCAST_CONNECTION` | `log` | no | Unused |

API tokens are Laravel Sanctum bearer tokens; the optional vars `SANCTUM_STATEFUL_DOMAINS` and `SANCTUM_TOKEN_PREFIX` exist but need no change for the standard setup.

### Mail

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `MAIL_MAILER` | `log` | no | The core app does not send email; invites are shared as links. Mail is only written to the log |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | `avapeek@localhost`, `${APP_NAME}` | no | |

### Object storage

Full provider-specific instructions are in [storage.md](storage.md).

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `FILESYSTEM_DISK` | `s3` | yes | Leave as `s3` (Garage and external providers both use it) |
| `AWS_ACCESS_KEY_ID` | generated for Garage | yes | S3 access key. With Garage, must equal root `S3_ACCESS_KEY_ID` |
| `AWS_SECRET_ACCESS_KEY` | generated for Garage | yes | S3 secret. With Garage, must equal root `S3_SECRET_ACCESS_KEY` |
| `AWS_DEFAULT_REGION` | `garage` | yes | Garage: `garage`. AWS: your region. B2: e.g. `us-west-004`. R2: `auto` |
| `AWS_BUCKET` | `photos` | yes | Bucket name. The bundled Garage creates `photos` |
| `AWS_ENDPOINT` | `http://garage:3900` | provider-dependent | Empty for AWS S3. Required for Garage, B2, R2 |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` | provider-dependent | `true` for Garage, `false` for AWS, B2, R2 |
| `AWS_TEMPORARY_URL` | `${APP_URL}` | Garage only | Rewrites presigned URLs to your own origin (Caddy proxies `/photos/*` to Garage). **Leave empty for external providers** so presigned URLs point at the provider |
| `AWS_URL` | unset | no | Public bucket URL; Avapeek keeps buckets private, so normally unused |

### Storage quota

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `STORAGE_LIMIT_BYTES` | unset | no | Hard upload limit in bytes for all users combined, e.g. `10737418240` for 10 GB. Takes precedence over `STORAGE_VOLUME_PATH` |
| `STORAGE_VOLUME_PATH` | `/garage-data` | no | If set to an existing directory (and no `STORAGE_LIMIT_BYTES`), the limit is **90% of the free space on that volume** at the time of the check (not total size). The compose file mounts the Garage data volume read-only at `/garage-data`. **For external S3 providers, set it empty**: otherwise the quota would be derived from the empty local Docker volume's host disk. `up.sh --storage external` does this for you |

With neither set the limit is "unknown": uploads are not capped by Avapeek and the UI shows usage without a limit.

### Database backups

See [installation: backups](installation.md#backups). The `scheduler` container runs `backup:database` daily at 02:30 UTC.

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `BACKUP_AWS_BUCKET` | unset | no | Bucket for database dumps. **All-or-nothing:** when set, the backup disk uses only the `BACKUP_AWS_*` values below; when empty or unset it uses the photo `AWS_*` settings (and the command warns that backups share a failure domain with the photos) |
| `BACKUP_AWS_ACCESS_KEY_ID` / `BACKUP_AWS_SECRET_ACCESS_KEY` | unset | with `BACKUP_AWS_BUCKET` | Credentials for the backup bucket. A write/list-only key plus a provider lifecycle rule is the safest setup |
| `BACKUP_AWS_DEFAULT_REGION` | unset | with `BACKUP_AWS_BUCKET` | Region of the backup bucket |
| `BACKUP_AWS_ENDPOINT` | unset | provider-dependent | Endpoint of the backup provider (empty for AWS S3) |
| `BACKUP_AWS_USE_PATH_STYLE_ENDPOINT` | `false` | provider-dependent | `true` for Garage, `false` for AWS, B2, R2 |
| `BACKUP_RETENTION_DAYS` | `14` | no | Dumps older than this are deleted after a successful upload; the newest 3 are always kept. `0` disables app-side deletes |
| `BACKUP_AGE_RECIPIENT` | unset | no | Public `age1...` recipient. When set, dumps are encrypted with `age` before upload (`.dump.age`). Never put the private identity on the server |

### Uploads

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `UPLOADS_DIRECT` | `true` | no | Browsers upload encrypted blobs straight to object storage with presigned PUT URLs. Set to `false` to stream uploads through the PHP backend instead (`POST /api/photos/upload`) |

**Upgrading operators on an external S3 provider** (AWS S3, B2, R2): direct uploads are on by default and need bucket CORS to allow `PUT` (`AllowedMethods`: `GET`, `HEAD`, `PUT`; `AllowedHeaders` covering `Content-Type` and `Cache-Control`, or `*`). Update the CORS rule (see [storage.md](storage.md)) and run `php artisan storage:check`, or set `UPLOADS_DIRECT=false`. If you run your own reverse proxy in front of the bundled Garage setup, it may need a larger request body limit for `/photos/*`.

Things to know about direct uploads:

- The quota check of the proxy `POST /api/photos/upload` does not count live direct-upload reservations (it only compares completed usage with the limit). Direct uploads count both completed usage and other live reservations. With a mixed workload the limit can therefore be exceeded briefly; `storage:reconcile` corrects the recorded usage.
- If `POST /api/photos/upload-intent` answers 404 (direct uploads disabled, or a transient 404 from a proxy or deploy in between), the browser switches to the proxy upload and stops trying direct uploads until the page is reloaded.
- Each user may hold at most 20 live (unexpired, 15 minute) upload intents; beyond that `upload-intent` answers 429.

## Behaviour that is not configurable via env

- Rate limits (per minute unless noted): API 60, login 5, register 3, MFA 10, password-confirm 10, upload 30 (also applied to completing a direct upload), upload-intent 60, invites 10 per hour.
- Photos are capped at 50 MB (ciphertext) plus a 5 MB thumbnail. The 64 MB per-request PHP cap (`docker/php/uploads.ini`) applies only to the proxy fallback (`UPLOADS_DIRECT=false`).
- Registration is open only until the first account exists; afterwards it is invite-only.
