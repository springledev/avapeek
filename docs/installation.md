# Installation

This guide takes you from a fresh server to a running Avapeek. The short version is in the [README](../README.md#quickstart).

## Requirements

| Item | Minimum | Notes |
| --- | --- | --- |
| CPU | 1 core, amd64 or arm64 | Raspberry Pi 4/5 and ARM NAS devices work (images are multi-arch) |
| RAM | 1 GB (2 GB recommended) | PostgreSQL, Redis, FrankenPHP, nginx, Caddy and optionally Garage |
| Disk | OS + about 3 GB for images and the database, plus your photos | Photos are stored encrypted, roughly their original size plus a thumbnail |
| Docker | Engine 24+ with **Compose 2.20 or newer** | `docker compose version`. 2.20 is needed for optional `depends_on` |
| `openssl` | any | Used by `up.sh` to generate secrets (no PHP needed on the host) |
| Domain + DNS | an A/AAAA record pointing at the server | Required for HTTPS. Browsers only expose the Web Crypto API on HTTPS or `localhost`, so **a plain-HTTP remote install will not work** |
| Ports | 80 and 443 on the machine that terminates TLS | The Avapeek stack itself publishes only host port 88 (see below) |

## Choose how to get the software

| Path | What happens | When to use |
| --- | --- | --- |
| **Prebuilt images (default)** | `up.sh` pulls `ghcr.io/springledev/avapeek-backend`, `-frontend`, `-garage-init` | Normal installs. No compiler toolchain, fast |
| **Build from source** (`--build`) | Images are built locally from this checkout | You changed the code, or no release has been published for your architecture |

Pin a release by setting `AVAPEEK_VERSION=0.2.0` in the root `.env` (default `latest`).

## Install with `up.sh`

```sh
git clone https://github.com/springledev/avapeek.git
cd avapeek
./up.sh
```

`up.sh` will:

1. check for Docker, Compose >= 2.20 and `openssl`;
2. create `.env` and `backend/.env` from the `*.env.production.example` files and generate every secret (`APP_KEY`, database and Redis passwords, and for Garage the RPC secret, admin token and S3 key pair). Existing values are **never** overwritten; only empty or placeholder values are filled;
3. ask for your public domain and storage mode (or use flags, below);
4. pull the images, start the stack with `docker compose up -d`, wait for the backend's `/up` health endpoint, and run `php artisan migrate --force`;
5. run `php artisan storage:check` and print the URL.

Non-interactive form:

```sh
./up.sh --domain photos.example.com --storage garage --yes
```

| Flag | Meaning |
| --- | --- |
| `--domain HOST` | Public hostname. `photos.example.com` becomes `https://photos.example.com`. `localhost` or an IP becomes `http://HOST:88` |
| `--url URL` | Full public URL; overrides `--domain` (use for a non-standard HTTPS port) |
| `--storage garage\|external` | Bundled Garage, or an external S3 provider (sets `COMPOSE_PROFILES`) |
| `--build` | Build images from source instead of pulling |
| `--yes`, `-y` | Never prompt |
| `--env-only` (`--dry-run`) | Only write/complete the `.env` files; start nothing |
| `--skip-check` | Skip `storage:check` at the end |

For `--storage external`, `up.sh` clears the Garage defaults (`STORAGE_VOLUME_PATH`, `AWS_ENDPOINT`, `AWS_TEMPORARY_URL`, path-style) and then stops if the credentials are empty. Edit `backend/.env` using [storage.md](storage.md), configure bucket CORS, and re-run `./up.sh`. It also sets `STORAGE_VOLUME_PATH` empty only if it still has the shipped value `/garage-data`; a value you set yourself is kept.

The first user to open the site can register freely; every later account needs an invite. See [First account](#first-account).

## Manual install (without `up.sh`)

```sh
cp .env.production.example .env
cp backend/.env.production.example backend/.env
chmod 600 .env backend/.env
```

Fill in the values (every variable is explained in [configuration.md](configuration.md)):

```sh
# APP_KEY (backend/.env)
echo "base64:$(openssl rand -base64 32)"
# Passwords: DB_PASSWORD and REDIS_PASSWORD must match in .env and backend/.env
openssl rand -hex 24
# Garage only (root .env): COMPOSE_PROFILES=garage plus
openssl rand -hex 32          # GARAGE_RPC_SECRET, GARAGE_ADMIN_TOKEN
echo "GK$(openssl rand -hex 12)"   # S3_ACCESS_KEY_ID
openssl rand -hex 32          # S3_SECRET_ACCESS_KEY
```

Set in `backend/.env`: `APP_URL` and `FRONTEND_URL` to your public URL; for Garage `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` equal to the root `S3_ACCESS_KEY_ID` / `S3_SECRET_ACCESS_KEY` and `AWS_TEMPORARY_URL` equal to `APP_URL`.

Start it (prebuilt images, or drop the second `-f` and use `up -d --build` to build):

```sh
docker compose -f docker-compose.prod.yml -f docker-compose.release.yml pull
docker compose -f docker-compose.prod.yml -f docker-compose.release.yml up -d --no-build
docker compose -f docker-compose.prod.yml -f docker-compose.release.yml exec backend php artisan migrate --force
docker compose -f docker-compose.prod.yml -f docker-compose.release.yml exec backend php artisan storage:check
```

`--no-build` matters: it tells Compose to use the pulled images rather than building the `build:` sections. The backend container runs `config:cache` and `route:cache` on every start in production, so after editing `backend/.env` just recreate it with `up -d`.

## TLS and reverse proxies

The bundled Caddy (`docker/caddy/Caddyfile`) listens on **plain HTTP** inside the stack and is published on host port **88**. It routes:

- `/api/*` to the backend (FrankenPHP/Laravel),
- `/photos/*` to Garage (only used with the Garage profile; this is what makes presigned URLs work on your own origin),
- everything else to the frontend (nginx serving the SPA).

It does **not** obtain certificates. Put a TLS-terminating proxy in front of port 88 on the same host or network, forwarding **every path** to it. Requirements for that proxy:

- forward all paths, including `/photos/*`;
- allow request bodies of at least 64 MB (the backend accepts uploads up to 64 MB);
- keep the original `Host` header or not, both work; but the public URL must equal `APP_URL`.

Caddy on the host (automatic certificates):

```caddyfile
photos.example.com {
    request_body {
        max_size 100MB
    }
    reverse_proxy 127.0.0.1:88
}
```

nginx:

```nginx
server {
    listen 443 ssl;
    server_name photos.example.com;
    # ssl_certificate / ssl_certificate_key ...
    client_max_body_size 100m;

    location / {
        proxy_pass http://127.0.0.1:88;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

Traefik: route `Host(`photos.example.com`)` to a service at `http://<docker-host>:88`, or attach Traefik to the stack network `app-network` and target `caddy:80`.

## Installing as an app

Avapeek is an installable PWA. Browsers only offer installation (and run the service worker) on HTTPS or `localhost`, so a plain-HTTP remote install cannot be installed. On Android Chrome, "Share" to Avapeek from the gallery works once the app is installed; iOS Safari supports Add to Home Screen but not sharing into the app.

## First account

Open your URL. While the database has no users, registration is open: create the first account. From then on `/api/auth/registration-mode` reports `invite_only` and new accounts can only be created through an invite link. Create a child, then use its invite action to generate a link for each relative. Choose a strong password and **write it down**: there is no password reset (see [security.md](security.md#password-loss)).

## Upgrading

```sh
git pull          # updates up.sh, compose files and docs
./up.sh           # pulls newer images, recreates containers, runs migrations
```

Using a pinned `AVAPEEK_VERSION`? Change it in `.env` first. Secrets and settings are untouched. With `--build`, the images are rebuilt from your updated checkout. Read the release notes for any manual steps.

## Backups

> **WARNING: the PostgreSQL database holds every user's wrapped encryption keys. If you lose the database, all photos become permanently unreadable even if the storage bucket is intact.** Back up the database and the bucket together, and test restoring.

What to back up:

1. **Database** (nightly at least):

   ```sh
   docker compose -f docker-compose.prod.yml exec -T postgres \
     sh -c 'pg_dump -U "$POSTGRES_USER" "$POSTGRES_DB"' | gzip > avapeek-db-$(date +%F).sql.gz
   ```

   Restore into an empty database with `gunzip -c file.sql.gz | docker compose -f docker-compose.prod.yml exec -T postgres sh -c 'psql -U "$POSTGRES_USER" "$POSTGRES_DB"'`.
2. **Photo storage.** Garage: stop it for a consistent copy and archive its volumes (`docker volume ls` shows `<project>_garage_meta` and `<project>_garage_data`):

   ```sh
   docker compose -f docker-compose.prod.yml stop garage
   docker run --rm -v <project>_garage_meta:/meta -v <project>_garage_data:/data -v "$PWD":/backup alpine \
     tar czf /backup/garage-$(date +%F).tar.gz /meta /data
   docker compose -f docker-compose.prod.yml start garage
   ```

   External S3: enable the provider's versioning/replication, or sync the bucket with `rclone`.
3. **Configuration:** `.env` and `backend/.env` (they contain `APP_KEY` and the S3 credentials). Store them separately from the data backups.

Keep database and bucket backups from around the same time. `php artisan storage:reconcile` recomputes storage usage from the database and lists (optionally deletes) objects not referenced by it.

## Uninstall

```sh
docker compose -f docker-compose.prod.yml down        # stop, keep data
docker compose -f docker-compose.prod.yml down -v     # stop AND DELETE all volumes (database, Garage data)
```

Delete the checkout and, if used, the external bucket yourself.
