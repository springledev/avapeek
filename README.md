# Avapeek

[![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-blue.svg)](LICENSE)

**End-to-end encrypted family photo sharing, self-hosted.**

Avapeek lets you share photos of your children with grandparents, relatives and friends without trusting a server with them. Photos, thumbnails, EXIF data and comments are encrypted in the browser before they are uploaded. The database, the backups and the object storage only ever hold ciphertext. (A malicious or compromised server operator is a different matter: see the security notes below.) You run all of it yourself, on a small VPS, a NAS or a Raspberry Pi.

> Screenshots: _to be added._

## Why Avapeek?

Compared with social media, shared albums or family photo apps, Avapeek is built around three things:

- **Privacy by design.** Photos, EXIF, filenames, tag names and comments are encrypted in your browser. Your server, database, backups and storage only hold ciphertext. There are no ads, no trackers, and nothing for anyone to train an AI on.
- **You own it.** Self-host on your own hardware and choose your own storage. Open source (AGPL-3.0). Any member can export everything, so there is no lock-in.
- **Family only.** One group per child, invite-only, with owner/admin/member roles and optional view-only members. No public profiles, no followers.

The honest trade-offs: you run the server and the backups, a forgotten password means lost photos (no reset exists), and the feature set is smaller than the big platforms. Read the full comparison in [Why Avapeek?](docs/why-avapeek.md).

> **Hosted version coming soon** for people who cannot or do not want to self-host.

## Features

- Client-side AES-GCM encryption of photos, thumbnails, low-quality placeholders, EXIF and comments
- Per-child "families": invite relatives with a link, they get access to that child's photos only
- Unified feed, recents, tags, date filters and encrypted comments
- Optional two-factor authentication (TOTP, passkeys, recovery codes)
- Storage of your choice: bundled self-hosted [Garage](https://garagehq.deuxfleurs.fr/), or any S3-compatible provider (AWS S3, Backblaze B2, Cloudflare R2, ...)
- Mobile-first web app (Vue 3), Laravel 13 API, PostgreSQL, Redis
- Multi-arch container images (amd64, arm64) published to GHCR

## Security model in brief

Your password derives a key in your browser (PBKDF2, 600,000 iterations). That key wraps your private key and the per-child data keys; those wrap per-photo keys. The database and bucket store only wrapped keys and ciphertext, so a leaked database or bucket does not expose your photos. It does not protect against a malicious server operator, because the app that does the encryption is delivered by the server (when you self-host, that operator is you). Your password itself is never sent, only a one-way auth hash. Some metadata (child names, photo dates and sizes, who belongs to which child) is **not** encrypted today, and there is **no password recovery**: a forgotten password means the data is unreadable. Read [docs/security.md](docs/security.md) before trusting it with anything important.

## Quickstart

Requirements: Docker with Compose 2.20 or newer, `openssl`, and a machine with 1 GB of RAM or more.

```sh
git clone https://github.com/springledev/avapeek.git
cd avapeek
./up.sh --domain localhost --storage garage --yes   # or just ./up.sh for prompts
# open the URL it prints (http://localhost:88)
# register the first account: it is the only account that can sign up freely
```

`up.sh` generates all secrets, pulls the prebuilt images from `ghcr.io/springledev`, starts the stack, waits for it to be healthy and runs the database migrations. Re-run it any time to upgrade. For a real domain with HTTPS, see the [installation guide](docs/installation.md#tls-and-reverse-proxies).

> **Back up your database.** It holds the wrapped encryption keys; losing it means losing every photo, even if the bucket survives. See [backups](docs/installation.md#backups).

## Documentation

| Guide | Contents |
| --- | --- |
| [Installation](docs/installation.md) | Requirements, `up.sh`, manual install, TLS, upgrades, backups, uninstall |
| [Configuration](docs/configuration.md) | Every environment variable |
| [Storage](docs/storage.md) | Garage, AWS S3, Backblaze B2, Cloudflare R2, CORS, quota |
| [Security](docs/security.md) | Encryption design, what the server can see, threat model, limitations |
| [Roadmap and known issues](docs/roadmap.md) | Planned work and current limitations |
| [Architecture](docs/architecture.md) | Components and request flows |
| [Development](docs/development.md) | Dev environment, tests, repo layout |
| [Why Avapeek?](docs/why-avapeek.md) | How it compares with social media, shared albums and family photo apps |
| [FAQ and troubleshooting](docs/faq.md) | Common problems |

## Feedback

Avapeek is not accepting outside contributions or pull requests at this time. Bug reports are welcome as [GitHub issues](https://github.com/springledev/avapeek/issues). Report security issues privately as described in [SECURITY.md](SECURITY.md).

## License

Avapeek is licensed under the [GNU Affero General Public License v3.0 or later](LICENSE). If you run a modified version as a network service, you must offer its source to your users.
