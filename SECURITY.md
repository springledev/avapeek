# Security policy

## Reporting a vulnerability

Please report security issues privately. Do **not** open a public issue or pull request.

**TODO (maintainer): replace this placeholder before publishing.** Contact: `security@example.invalid` (or enable GitHub private vulnerability reporting for `springledev/avapeek` and link it here).

Please include what you found, steps to reproduce, and the affected version. We aim to acknowledge reports within a few days. Because Avapeek is a small project run by volunteers, fix timelines depend on severity and availability.

## Scope

In scope: the Laravel API, the Vue app, the encryption design and its use of `frontend/src/crypto/`, the Docker/compose setup. Particularly interesting: anything that lets a user read another family's data, bypass membership checks, or makes the server receive plaintext photo or comment data.

Known limitations are listed in [docs/security.md](docs/security.md#known-limitations) and are not vulnerabilities by themselves.

## Supported versions

Only the latest release receives security fixes.
