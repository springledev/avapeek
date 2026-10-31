# Changelog

All notable changes to Avapeek are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases follow [Semantic Versioning](https://semver.org/). The git tag `vX.Y.Z` is the single source of truth for a version.

## [Unreleased]

## [0.1.0] - TBD

First public release.

### Added

- Laravel 13 API (PHP 8.5) with PostgreSQL and Redis, and a mobile-first Vue 3 progressive web app
- Photo upload with client-side thumbnails and low-quality placeholders, a unified feed, recents and a photo viewer with prev/next navigation
- Per-child "families" with owner, admin and member roles, optional view-only members, and invite links with pending-invite revoke
- Encrypted per-child tags, date and month filters, and a memories view
- Encrypted photo comments, with comment-count badges in the feed
- Multi-select with bulk download, remove, tag and share to another child
- Export of a child's photos with names and metadata, so there is no lock-in
- Web push notifications and in-app notifications for admins when someone joins or comments, with per-user settings
- Optional two-factor authentication: TOTP, passkeys and recovery codes
- Password change with re-wrapping of keys, and invite key sharing between members
- Storage in the bundled Garage or any S3-compatible provider, with `storage:check`, quota reporting and storage reconcile
- `backup:database` and `backup:verify` commands with a scheduled backup
- Dark mode, empty states, loading states and safe-area handling for the installed app
- `up.sh` installer that generates secrets, pulls images, starts the stack and runs migrations
- Multi-arch (amd64, arm64) container images published to GHCR on version tags, with a GitHub Release for each tag
- Documentation for installation, configuration, storage and the security model

### Security

- Photos, thumbnails, placeholders, EXIF, filenames, tag names and comments are encrypted with AES-GCM in the browser; the server only stores ciphertext
- The password is never sent to the server, only a one-way auth hash; keys are derived with PBKDF2 (600,000 iterations)
- Per-photo keys are wrapped by per-child keys, which are wrapped by the user key, using random IVs
- Photo access is checked through child membership on every request
- Push endpoints are guarded against SSRF
- Security reports go through private vulnerability reporting
