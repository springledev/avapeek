# Security policy

## Reporting a vulnerability

Please report security issues privately. Do **not** open a public issue or pull request.

Use GitHub private vulnerability reporting: open the [Security tab](https://github.com/springledev/avapeek/security) of the repository and choose **Report a vulnerability** ([direct link](https://github.com/springledev/avapeek/security/advisories/new)).

Please include what you found, steps to reproduce, and the affected version. I aim to acknowledge reports within a few days. Avapeek is maintained by a single developer, so fix timelines depend on severity and availability.

## Scope

In scope: the Laravel API, the Vue app, the encryption design and its use of `frontend/src/crypto/`, the Docker/compose setup. Particularly interesting: anything that lets a user read another family's data, bypass membership checks, or makes the server receive plaintext photo or comment data.

Known limitations are listed in [docs/security.md](docs/security.md#known-limitations) and are not vulnerabilities by themselves.

## Supported versions

Only the latest release receives security fixes.
