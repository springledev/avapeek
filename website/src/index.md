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

<p class="lead">End-to-end encrypted family photo sharing. Share photos of your children with grandparents, relatives and friends, and nobody else.</p>

Photos, EXIF data, tags and comments are encrypted in the browser before they are uploaded, so the server only ever stores data it cannot read. Run it yourself on a small VPS, a NAS or a Raspberry Pi. Avapeek is open source (AGPL-3.0-or-later).

- **Private by design.** No ads, no trackers, nothing to train an AI on. See [how the encryption works](/security).
- **Yours.** Pick your own storage, and any member can export everything.
- **Family only.** One group per child, invite-only. No public profiles, no followers.

Two things to know up front: there is **no password reset**, and the project has **not been independently audited** yet. More in [Why Avapeek?](/why)

## Quickstart

Requirements: Docker with Compose 2.20 or newer, `openssl`, and a machine with 1 GB of RAM or more.

```sh
git clone https://github.com/springledev/avapeek.git
cd avapeek
./up.sh --domain localhost --storage garage --yes   # or just ./up.sh for prompts
```

Open the URL it prints (`http://localhost:88`) and register the first account. Only the first account can sign up freely; everyone else joins by invite.

`up.sh` generates all secrets, pulls the prebuilt images, starts the stack and runs the database migrations. Re-run it to upgrade. To let your family reach it from outside your home, see [external access](/docs/installation#reaching-avapeek-from-outside-your-home): Cloudflare Tunnel, Cloudflare Access, a VPN or your own HTTPS proxy.

::: warning Back up your database
It holds the wrapped encryption keys. Losing it means losing every photo, even if the storage bucket survives. See [backups](/docs/installation#backups).
:::

## Documentation

<ul class="entry-points">
  <li><a href="./docs/installation">Installation</a><p>Requirements, <code>up.sh</code>, external access, TLS, upgrades and backups.</p></li>
  <li><a href="./docs/storage">Storage</a><p>Garage, AWS S3, Backblaze B2 or Cloudflare R2.</p></li>
  <li><a href="./security">How the encryption works</a><p>What is encrypted and what the server can see.</p></li>
  <li><a href="./docs/faq">FAQ and troubleshooting</a><p>Common problems and their fixes.</p></li>
</ul>

## Feedback

Bug reports are welcome as [GitHub issues](https://github.com/springledev/avapeek/issues). Outside contributions and pull requests are not accepted at this time. Report security issues privately as described in the [security policy](/security-policy).
