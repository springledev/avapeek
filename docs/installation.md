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

Pin a release by setting `AVAPEEK_VERSION=0.1.0` in the root `.env` (default `latest`).

## Install with `up.sh`

```sh
git clone https://github.com/springledev/avapeek.git
cd avapeek
./up.sh
```

`up.sh` will:

1. check for Docker, Compose >= 2.20 and `openssl`;
2. create `.env` and `backend/.env` from the `*.env.production.example` files and generate every secret (`APP_KEY`, database and Redis passwords, the Web Push key pair, and for Garage the RPC secret, admin token and S3 key pair). Existing values are **never** overwritten; only empty or placeholder values are filled;
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
| `--demo` | Add a made-up family to a new instance, see [demo data](#try-it-with-demo-data) |
| `--build` | Build images from source instead of pulling |
| `--yes`, `-y` | Never prompt |
| `--env-only` (`--dry-run`) | Only write/complete the `.env` files; start nothing |
| `--skip-check` | Skip `storage:check` at the end |

For `--storage external`, `up.sh` clears the Garage defaults (`STORAGE_VOLUME_PATH`, `AWS_ENDPOINT`, `AWS_TEMPORARY_URL`, path-style) and then stops if the credentials are empty. Edit `backend/.env` using [storage.md](storage.md), configure bucket CORS, and re-run `./up.sh`. It also sets `STORAGE_VOLUME_PATH` empty only if it still has the shipped value `/garage-data`; a value you set yourself is kept.

The first user to open the site can register freely; every later account needs an invite. See [First account](#first-account).

### Try it with demo data

To look around before setting up anything real, install on your own machine with demo data:

```sh
./up.sh --domain localhost --storage garage --yes --demo
```

This adds a made-up family: two children, four accounts with different roles, and 36 generated pictures with tags and comments, all encrypted the same way as real photos. The command prints the accounts and their shared password. Try signing in as the owner and as the view-only grandparent. **Anyone who can reach the instance can use these accounts, so do not do this on a public server.**

When you are done, either [uninstall](#uninstall), or keep the install and start for real:

```sh
docker compose -f docker-compose.prod.yml exec backend php artisan avapeek:demo-clear
```

It lists what it will delete and asks you to confirm (`--force` skips the question in scripts). It removes the demo accounts, their children, photos and stored files, and you can then register your first account as usual. Only demo data is touched: real accounts and their photos stay, and the command refuses to run at all if real data is tied to the demo family, for example a real account uploaded into a demo child, or a demo child was transferred to a real account. Demo data can only be added while the instance has no accounts.

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

## Reaching Avapeek from outside your home

Your family needs to reach the app from their own phones, and the app only works over HTTPS (or on `localhost`): browsers do not offer the encryption API, the service worker or push notifications on plain HTTP. Forwarding port 88 on your router is therefore not an option.

| Option | Best for | What you need | Trade-off |
| --- | --- | --- | --- |
| [Cloudflare Tunnel](#cloudflare-tunnel) (recommended) | Most home installs, including behind CGNAT | A free Cloudflare account and a domain on Cloudflare | Cloudflare sits in the middle of the connection |
| [Tunnel plus Cloudflare Access](#adding-cloudflare-access) | Keeping everyone but your family away from the app altogether | The same, plus a list of your family's email addresses | A second sign-in for your family, and more to maintain |
| [A VPN](#a-vpn-such-as-tailscale-or-wireguard) | A family that will all install and keep using a VPN app (rare) | A VPN on every device | Relatives cannot open an invite link until the VPN is set up |
| [Your own reverse proxy](#tls-and-reverse-proxies) | A VPS, or a home connection with a public IP | An open port 443 and a certificate | You expose your server's IP address and keep the proxy patched |

Whichever you choose, your photos, comments and tag names stay encrypted, because the browser encrypts them before they leave the device. Nobody between your family and your server can read them in transit.

### Cloudflare Tunnel

A small `cloudflared` container makes an outbound connection to Cloudflare, so you open no ports and your home IP address stays hidden. Cloudflare provides the HTTPS certificate.

1. Add your domain to Cloudflare, then go to **Networking > Tunnels** in the Cloudflare dashboard and create a tunnel. Choose Docker and copy the tunnel token. Keep it secret: anyone holding it can serve traffic for your tunnel.
2. Run `cloudflared` on the Avapeek network. The network is named after the folder you cloned into, so check it with `docker network ls` (it is `avapeek_app-network` by default):

   ```sh
   docker run -d --name cloudflared --restart unless-stopped \
     --network avapeek_app-network \
     cloudflare/cloudflared:latest tunnel --no-autoupdate run --token <TUNNEL_TOKEN>
   ```

3. Add a **Published application** route to the tunnel: your hostname (for example `photos.example.com`) with the service URL `http://caddy:80`.
4. Tell Avapeek its public address. This updates `APP_URL` and the related settings, and restarts the stack:

   ```sh
   ./up.sh --url https://photos.example.com
   ```

5. Open `https://photos.example.com` from a phone on mobile data, and upload a photo to check the whole route works.

Things worth knowing:

- **What Cloudflare can see.** Cloudflare terminates HTTPS, so it handles the same traffic your server does: ciphertext, but also account emails, child names and other plaintext metadata, your one-way auth hash and session tokens. It also delivers the app's code to the browser, so like the operator of the server it would be in a position to alter it. If that does not fit your [threat model](security.md), use a VPN or your own reverse proxy.
- **Upload size.** Cloudflare's Free and Pro plans accept requests up to 100 MB, above Avapeek's 64 MB limit, so photos are not affected.
- **Features that rewrite pages.** Turn off Cloudflare features that inject scripts, such as Rocket Loader. Avapeek's Content-Security-Policy blocks them and they can break the app.
- **Availability.** If Cloudflare or your home connection is down, so is the app. Keep your [backups](#backups) outside your home.

### Adding Cloudflare Access

[Cloudflare Access](https://developers.cloudflare.com/cloudflare-one/access-controls/policies/) puts a login page in front of a tunnel, so requests from anyone not on your list never reach your server. Avapeek's login, invite and API endpoints are no longer exposed to the internet at large.

Create a self-hosted application for your hostname under **Zero Trust > Access**, with an Allow policy listing your family's email addresses and one-time PIN by email as the login method. Check [Cloudflare's plans](https://www.cloudflare.com/plans/) for the current limit on free users.

Decide with your family in mind, because it adds friction:

- **Everyone signs in twice:** once to Access, then with their Avapeek password. Access does not replace the Avapeek password, which is what unlocks the photos.
- **Invite links need an extra step.** Add a relative's email to the policy before sending them the link, or the link stops at the Access page.
- **Sessions expire.** When an Access session ends, the installed app can no longer reach the API and shows connection errors until the person signs in to Access again, which can be awkward in an app installed to the home screen. Choose a long session duration.
- **Do not allow "everyone with a valid email".** A policy that only requires the one-time PIN login method lets anyone who has an email address in.

For most families Tunnel alone is enough, since Avapeek already requires an invite to register. Consider Access if you would rather not have the login page reachable by the public.

### A VPN, such as Tailscale or WireGuard

If everyone in your family will realistically install a VPN app on every device and leave it on, a VPN is the most private option: nothing is exposed to the internet and no third party terminates HTTPS. In practice that is rarely true of grandparents, and an invite link does nothing until the relative is on the VPN.

The app still needs HTTPS, even inside a VPN. With [Tailscale](https://tailscale.com/docs/reference/tailscale-cli/serve), `tailscale serve` provides a certificate for your machine's `ts.net` name:

```sh
tailscale serve --bg 88
./up.sh --url https://<machine>.<tailnet>.ts.net
```

With plain WireGuard, run a [reverse proxy](#tls-and-reverse-proxies) with a certificate for a name that resolves to the server's VPN address, for example one obtained through a DNS challenge.

Push notifications keep working when a device is off the VPN, because they arrive through the browser vendor's push service. Opening the app from one still needs the VPN.

## TLS and reverse proxies

The bundled Caddy (`docker/caddy/Caddyfile`) listens on **plain HTTP** inside the stack and is published on host port **88**. It routes:

- `/api/*` to the backend (FrankenPHP/Laravel),
- `/photos/*` to Garage (only used with the Garage profile; this is what makes presigned URLs work on your own origin),
- everything else to the frontend (nginx serving the SPA).

It does **not** obtain certificates. Put a TLS-terminating proxy in front of port 88 on the same host or network, forwarding **every path** to it. Requirements for that proxy:

- forward all paths, including `/photos/*`;
- allow request bodies of at least 64 MB (the backend accepts uploads up to 64 MB);
- keep the original `Host` header or not, both work; but the public URL must equal `APP_URL`;
- don't strip the security headers Caddy adds (Content-Security-Policy and others). If you replace the bundled Caddy with your own proxy, copy the `header` block from `docker/caddy/Caddyfile` and use the output of `docker compose -f docker-compose.prod.yml exec backend php artisan storage:csp-origin` in `connect-src`.

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

Releases follow [SemVer](https://semver.org/) and are tagged `vX.Y.Z`; the image tag is the version without the `v`. Pinning `AVAPEEK_VERSION` to an exact version is recommended so upgrades happen when you choose. To upgrade a pinned install, change it in `.env` first. Secrets and settings are untouched. With `--build`, the images are rebuilt from your updated checkout. Read the release notes for any manual steps.

## Backups

> **WARNING: the PostgreSQL database holds every user's wrapped encryption keys. If you lose the database, all photos become permanently unreadable even if the storage bucket is intact.** Back up the database and the bucket together, and test restoring.

What to back up:

1. **Database: automatic.** The `scheduler` container runs `php artisan backup:database` every night at 02:30 UTC. It runs `pg_dump --format=custom`, uploads the dump to `backups/db/avapeek-<UTC timestamp>.dump` on the backup disk, then deletes dumps older than `BACKUP_RETENTION_DAYS` (default 14; the newest 3 are always kept). Nothing extra to start: it uses the existing scheduler. Settings are in [configuration](configuration.md#database-backups).

   - **Use a separate destination.** By default dumps go into the photo bucket, so one incident loses both. Set `BACKUP_AWS_BUCKET` (and the other `BACKUP_AWS_*` values) to a different bucket or provider. The command prints a WARNING while backups share the photo bucket.
   - **The dump is sensitive.** It contains wrapped keys (password guessing against a leaked dump is possible, see [security](security.md)) and **plaintext** emails, child names and invite data. Protect the destination, or set `BACKUP_AGE_RECIPIENT=age1...` to encrypt each dump with [age](https://github.com/FiloSottile/age). Generate the key pair on your own machine (`age-keygen -o key.txt`), put only the public recipient on the server and keep `key.txt` offline. Without that key encrypted dumps are unrecoverable.
   - **Run and check it by hand:**

     ```sh
     docker compose -f docker-compose.prod.yml exec scheduler php artisan storage:check --backup
     docker compose -f docker-compose.prod.yml exec scheduler php artisan backup:database
     docker compose -f docker-compose.prod.yml exec scheduler php artisan backup:verify            # pg_restore --list on the newest dump
     docker compose -f docker-compose.prod.yml exec scheduler php artisan backup:verify --restore  # full test restore into a throwaway database
     ```

     `backup:verify` also runs weekly (Sunday 03:30 UTC) and logs the result. For encrypted dumps, `--identity=<file>` is needed to look inside; without it only the age header is checked. Do not leave the identity on the server.
   - **Monitor it.** If the scheduler is down, no backups run. Alert when the newest object under `backups/db/` is older than about 26 hours.

2. **Photo storage.** Garage: stop it for a consistent copy and archive its volumes (`docker volume ls` shows `<project>_garage_meta` and `<project>_garage_data`):

   ```sh
   docker compose -f docker-compose.prod.yml stop garage
   docker run --rm -v <project>_garage_meta:/meta -v <project>_garage_data:/data -v "$PWD":/backup alpine \
     tar czf /backup/garage-$(date +%F).tar.gz /meta /data
   docker compose -f docker-compose.prod.yml start garage
   ```

   External S3: enable the provider's versioning/replication, or sync the bucket with `rclone`.
3. **Configuration:** `.env` and `backend/.env` (they contain `APP_KEY` and the S3 credentials). Store them separately from the data backups.

Keep database and bucket backups from around the same time. `.env` and `backend/.env` are not part of the automatic backup.

### Restore

You need the **same `APP_KEY` and `backend/.env`** as before, and users still need their passwords: the password is the only way to unwrap the keys in the restored database.

1. Start an empty PostgreSQL: `docker compose -f docker-compose.prod.yml up -d postgres` (for a fresh database, remove the old `postgres_data` volume first).
2. Download the newest dump from `backups/db/` with any S3 tool (for example `aws s3 cp s3://<backup-bucket>/backups/db/avapeek-<timestamp>.dump . --endpoint-url <endpoint>`).
3. If it ends in `.age`, decrypt it: `age -d -i key.txt avapeek-<timestamp>.dump.age > avapeek-<timestamp>.dump`.
4. Restore:

   ```sh
   docker compose -f docker-compose.prod.yml exec -T postgres sh -c \
     'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --clean --if-exists --no-owner' < avapeek-<timestamp>.dump
   ```
5. Bring the rest of the stack up with `./up.sh`. It keeps your existing `.env` files, uses the same images as before and runs `php artisan migrate --force`, which applies any migrations newer than the dump.
6. Reconcile usage and list orphaned photo objects: `docker compose -f docker-compose.prod.yml exec backend php artisan storage:reconcile`.
7. Log in and open a photo to confirm it decrypts.

`php artisan storage:reconcile` recomputes storage usage from the database and lists (optionally deletes) objects not referenced by it.

## Uninstall

```sh
docker compose -f docker-compose.prod.yml down        # stop, keep data
docker compose -f docker-compose.prod.yml down -v     # stop AND DELETE all volumes (database, Garage data)
```

Delete the checkout and, if used, the external bucket yourself.
