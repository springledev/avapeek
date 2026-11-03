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
| `UPSTREAM_TRUSTED_PROXIES` | empty (off) | behind a proxy or tunnel | Space-separated IPs or CIDR ranges allowed to tell Caddy the client address. See [Client IP](#client-ip) |
| `UPSTREAM_CLIENT_IP_HEADER` | `X-Forwarded-For` | no | Header read from those sources. `CF-Connecting-IP` for Cloudflare Tunnel. See [Client IP](#client-ip) |

## Client IP

Rate limits on login, registration, pre-login, MFA and invite previews are per client IP. Caddy works out that address and passes it to the backend, which trusts only Caddy (`BACKEND_TRUSTED_PROXIES`, set to `caddy` by `docker-compose.prod.yml`; leave it alone).

**By default nothing upstream is trusted.** Caddy ignores `X-Forwarded-For`, `CF-Connecting-IP` and similar headers and uses the address that connected to it, so clients cannot forge another IP. If a proxy or tunnel connects to Caddy, that address is the proxy's, and all clients share one set of limits until you opt in:

| Deployment | `UPSTREAM_TRUSTED_PROXIES` | `UPSTREAM_CLIENT_IP_HEADER` |
| --- | --- | --- |
| Clients connect directly to port 88 | leave empty | leave unset |
| TLS proxy on another machine | that machine's IP | leave unset |
| TLS proxy on the Docker host, connecting to `127.0.0.1:88` | the Docker network's gateway | leave unset |
| TLS proxy in a container on `app-network` | the network's subnet | leave unset |
| Cloudflare Tunnel | the address `cloudflared` connects from, as in the proxy rows above | `CF-Connecting-IP` |

Find the gateway and subnet with `docker network inspect avapeek_app-network` (the prefix is your project directory's name). They can change if the network is recreated. Limits are then shared again until you update the value; forging does not become possible. Put the values in the root `.env` and re-run `./up.sh`.

**Anything listed can claim to be any client**, so:

- Make sure nothing else can reach port 88 from a listed address. Listing the Docker gateway is only safe if port 88 is not reachable from other machines: Docker-published ports usually bypass `ufw`, and on setups where Docker's userland proxy handles the connection (IPv6, rootless Docker, Docker Desktop) every client appears to come from the gateway. Block the port in your provider's or Docker's (`DOCKER-USER`) firewall.
- `CF-Connecting-IP` is only as trustworthy as the source. Only enable it when requests really come through the tunnel; choosing the header alone does nothing without a listed source.
- List only the proxy. Avoid wide ranges and `private_ranges` (Caddy's shorthand for all private addresses): every device on your LAN could then forge its IP, and a client inside a listed range counts as a proxy, not a client.
- The proxy must set the header to the address it sees, or append to it. Caddy reads `X-Forwarded-For` from the right and takes the first address that is not listed, so a client cannot get a new limit by prepending values. The [nginx example](installation.md#tls-and-reverse-proxies) does this.

## `backend/.env`

### Application

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `APP_NAME` | `Avapeek` | no | Shown in the TOTP authenticator app |
| `APP_ENV` | `production` | yes | Must stay `production`; the entrypoint only caches config/routes in production |
| `APP_KEY` | generated | yes | `base64:` + 32 random bytes. Encrypts sessions and cookies. Changing it signs everyone out; it does **not** touch photo encryption (that is client-side) |
| `APP_DEBUG` | `false` | no | Never enable in production |
| `APP_URL` | `https://your-domain.com` | yes | Public URL users type in. Used to build invite links and as the base for presigned URLs with Garage |
| `FRONTEND_URL` | same as `APP_URL` | yes | Origin the web app is served from (scheme, host and port must match exactly what the browser shows). Passkeys (WebAuthn) bind to it, CORS allows it, and `storage:check` uses it as `Origin` |
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
| `QUEUE_CONNECTION` | `redis` | no | Used by the `queue` service (Web Push batching and delivery). Must stay `redis` for the bundled worker |
| `BROADCAST_CONNECTION` | `log` | no | Unused |

API tokens are Laravel Sanctum bearer tokens; the optional vars `SANCTUM_STATEFUL_DOMAINS` and `SANCTUM_TOKEN_PREFIX` exist but need no change for the standard setup.

### Web Push notifications

Push is **off** until both VAPID keys are set: `GET /api/push/config` returns 404, the app hides the notification settings and the server makes no outbound push requests. `up.sh` generates a key pair when both keys are empty, so installs made with it have push on; it never replaces existing keys, and skips this when `PUSH_ENABLED` is `false`. For a manual install, generate a pair with `docker compose exec backend php artisan avapeek:push-keys` (it only prints; paste the lines into `backend/.env`). Keep the private key secret and do not rotate it casually: existing browser subscriptions are bound to the public key and stop working until each device re-subscribes. `up.sh` also sets `VAPID_SUBJECT` to your instance's `https://` URL while it is unset or still the placeholder; set your own `mailto:` address instead if you prefer.

| Name | Default | Required | Description |
| --- | --- | --- | --- |
| `PUSH_ENABLED` | `true` | no | Set `false` to switch push off even when keys are present |
| `VAPID_PUBLIC_KEY` | unset | for push | Sent to browsers so they can subscribe |
| `VAPID_PRIVATE_KEY` | unset | for push | Signs requests to push services. Never exposed |
| `VAPID_SUBJECT` | `mailto:admin@example.invalid` (`up.sh` sets your `https://` URL) | for push | Operator contact (`mailto:` or `https://` URL) shown to push services. Use an operator address, not a user's email |

Notes:

- Web Push needs HTTPS in the browser (localhost excepted). On plain-HTTP LAN installs push will not work. On iOS it only works for an installed home-screen app (16.4+).
- The `queue` service (`php artisan queue:work redis --max-time=3600`) sends the notifications. With `QUEUE_CONNECTION=redis` and the bundled Redis nothing else is needed. Restart it after changing `.env`; it is harmless when push is off.
- The backend and queue containers must be able to reach push services (Google, Apple, Mozilla, Microsoft) on outbound 443. Endpoints must be `https`, port 443 and must not resolve to private, loopback or link-local addresses; redirects are not followed and requests time out after 10 s.
- Pending events are kept in the cache for up to 1 hour, so a stopped or backlogged queue worker catches up after a restart; events older than that are dropped silently. A failed job puts its batch back so the retry (3 tries) still sends it.
- Bursts are batched: events are collected for 60 seconds, then each member gets one generic notification per type (photos, comments). Subscriptions that return 404/410, fail 5 times in a row, or have had no success for 60 days (`avapeek:push-prune`, daily) are deleted.

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

- Rate limits (per minute unless noted, keyed by user or [client IP](#client-ip)): API 60, login 5, register 3, MFA 10, password-confirm 10, upload 30 (also applied to completing a direct upload), upload-intent 60, invites 10 per hour.
- Photos are capped at 50 MB (ciphertext) plus a 5 MB thumbnail. The 64 MB per-request PHP cap (`docker/php/uploads.ini`) applies only to the proxy fallback (`UPLOADS_DIRECT=false`).
- Registration is open only until the first account exists; afterwards it is invite-only.
