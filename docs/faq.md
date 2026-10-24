# FAQ and troubleshooting

## Can I reset a forgotten password?

No. Your password derives the key that unwraps your encryption keys. There is no reset flow and no recovery key. If you forget it, your account's keys are lost. If another family member still has access to the same children, ask them to invite you again with a new account. See [security.md](security.md#password-loss). You can change your password while logged in (Settings), which re-wraps your keys.

## I lost my database. Can I recover the photos?

No. The database holds the wrapped keys; the bucket alone is unreadable ciphertext. Back up both ([backups](installation.md#backups)).

## "Registration is by invitation only"

Only the first account can register freely. Others need an invite link created by a child's admin.

## The page loads but nothing works, or I get CORS errors

- `APP_URL` and `FRONTEND_URL` in `backend/.env` must be exactly the URL in your browser (scheme, host, port). Recreate the backend afterwards: `docker compose -f docker-compose.prod.yml up -d`.
- The frontend calls `/api` on the same origin. Your reverse proxy must forward `/api/*` (and `/photos/*` for Garage) to the stack, not just `/`.
- Passkeys need HTTPS (or `localhost`) and `FRONTEND_URL` to match the origin.

## Login or encryption fails on a remote server over HTTP

Browsers expose the Web Crypto API only in secure contexts (HTTPS or `localhost`). Put TLS in front, see [TLS and reverse proxies](installation.md#tls-and-reverse-proxies).

## Photos upload but never display (Garage)

Presigned URLs must resolve to your own origin. Check `AWS_TEMPORARY_URL` equals `APP_URL` and that your proxy forwards `/photos/*`. Test as described in [storage.md](storage.md#what-storagecheck-does-not-cover).

## `SignatureDoesNotMatch`

- Garage: the `Host` header must be `garage:3900` when the request reaches Garage; the bundled Caddy sets it. If you route `/photos/*` to Garage in your own proxy, set it there too. `AWS_TEMPORARY_URL` must be the exact public origin.
- External providers: wrong secret, wrong `AWS_DEFAULT_REGION`, or wrong `AWS_USE_PATH_STYLE_ENDPOINT` (`true` for Garage, `false` for AWS/B2/R2).
- `php artisan storage:check` prints a hint for each.

## Images fail in the browser with a CORS error (external S3)

The browser fetches presigned URLs from the provider, so the bucket needs a CORS rule allowing `GET`/`HEAD` from your origin. Copy-paste rules are in [storage.md](storage.md).

## Uploads are rejected or the quota looks wrong

- Request bodies above 64 MB are refused. Make sure your proxy allows them (`client_max_body_size` for nginx).
- The limit comes from `STORAGE_LIMIT_BYTES`, or 90% of the free space on `STORAGE_VOLUME_PATH`, or is unknown. For external providers `STORAGE_VOLUME_PATH` should be empty ([configuration.md](configuration.md#storage-quota)).

## Port 88 is already in use

`docker-compose.prod.yml` publishes Caddy on host port 88. Stop whatever uses it, or change the port mapping in the compose file (`"88:80"`). Remember to point your TLS proxy at the new port.

## `up.sh` says Compose is too old

Install Docker Compose v2.20 or newer (`docker compose version`). Older versions do not support optional `depends_on`.

## `up.sh` cannot pull images

The release images are published by the maintainers' CI on tagged releases. If none exist for your architecture or version, use `./up.sh --build`.

## The backend container restarts

`docker compose -f docker-compose.prod.yml logs backend`. Typical causes: empty `APP_KEY`, database password mismatch between `.env` and `backend/.env`, or an old database volume with a different password (the password only applies when the volume is first created).

## Does removing a family member revoke their access to photos?

It removes their access through the app, but keys are not rotated, so someone who already saved the keys or decrypted photos retains them. See [limitations](security.md#known-limitations).

## Is it audited?

No.
