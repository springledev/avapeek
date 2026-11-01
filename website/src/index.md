---
title: Avapeek
titleTemplate: End-to-end encrypted family photo sharing
sidebar: false
aside: false
prev: false
next: false
editLink: false
---

# Avapeek

<p class="lead">End-to-end encrypted family photo sharing that you host yourself. Share photos of your children with grandparents, relatives and friends without trusting a server with them.</p>

Photos, thumbnails, EXIF data, tags and comments are encrypted in the browser before they are uploaded. The database, the backups and the object storage only ever hold ciphertext. You run it on a small VPS, a NAS or a Raspberry Pi.

Avapeek is open source (AGPL-3.0-or-later). The current version is in the [changelog](/changelog).

## What it is

- **Private by design.** Encryption happens on the device. No ads, no trackers, nothing to train an AI on.
- **Yours.** Self-hosted, with storage you choose: the bundled [Garage](https://garagehq.deuxfleurs.fr/) or any S3-compatible provider. Any member can export everything.
- **Family only.** One group per child, invite-only, with owner, admin and member roles and optional view-only members. No public profiles, no followers.

## What it is not

- **Not protected against a malicious server operator.** The app that does the encryption is delivered by the server. When you self-host, that operator is you. See the [threat model](/docs/security#threat-model).
- **No password recovery.** A forgotten password means that person's photos cannot be decrypted. See [password loss](/docs/security#password-loss).
- **Not fully metadata-private.** Child names, photo dates and sizes, and who belongs to which child are not encrypted today.
- **Not audited.** The crypto design is documented but has not had an independent review.

The full comparison with social media, shared albums and family photo apps is in [Why Avapeek?](/docs/why-avapeek)

## Quickstart

Requirements: Docker with Compose 2.20 or newer, `openssl`, and a machine with 1 GB of RAM or more.

```sh
git clone https://github.com/springledev/avapeek.git
cd avapeek
./up.sh --domain localhost --storage garage --yes   # or just ./up.sh for prompts
```

Open the URL it prints (`http://localhost:88`) and register the first account. Only the first account can sign up freely; everyone else joins by invite.

`up.sh` generates all secrets, pulls the prebuilt images, starts the stack and runs the database migrations. Re-run it to upgrade. For a real domain with HTTPS, follow the [installation guide](/docs/installation#tls-and-reverse-proxies).

::: warning Back up your database
It holds the wrapped encryption keys. Losing it means losing every photo, even if the storage bucket survives. See [backups](/docs/installation#backups).
:::

## Documentation

<ul class="entry-points">
  <li><a href="./docs/installation">Installation</a><p>Requirements, <code>up.sh</code>, manual install, TLS, upgrades, backups, uninstall.</p></li>
  <li><a href="./docs/configuration">Configuration</a><p>Every environment variable.</p></li>
  <li><a href="./docs/storage">Storage</a><p>Garage, AWS S3, Backblaze B2, Cloudflare R2, CORS and quota.</p></li>
  <li><a href="./docs/security">Security model</a><p>Key hierarchy, what the server can see, threat model and limitations.</p></li>
  <li><a href="./docs/faq">FAQ and troubleshooting</a><p>Common problems and their fixes.</p></li>
  <li><a href="./docs/architecture">Architecture</a><p>Components and the upload, view and export flows.</p></li>
</ul>

## Feedback

Bug reports are welcome as [GitHub issues](https://github.com/springledev/avapeek/issues). Outside contributions and pull requests are not accepted at this time. Report security issues privately as described in the [security policy](/security-policy).
