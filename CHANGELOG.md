# Changelog

All notable changes to Avapeek are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and releases follow [Semantic Versioning](https://semver.org/). The git tag `vX.Y.Z` is the single source of truth for a version.

## [Unreleased]

### Added

- `up.sh` generates the Web Push (VAPID) key pair when none is set, so push notifications work on new installs without a manual step. It also sets `VAPID_SUBJECT` to the instance's `https://` URL while it is still the placeholder. Existing keys are never replaced, and `PUSH_ENABLED=false` keeps push off
### Fixed

- The service worker never finished installing on any device, because the app icons were listed twice in its precache. Push notifications and the offline app shell now work
### Fixed

- Turning on notifications can no longer hang with the toggle greyed out: if the browser does not finish a step within 10 seconds, the app stops and says which step failed
- `php artisan avapeek:demo-seed` fills an empty instance with a made-up family (generated pictures, encrypted exactly as the browser does) and `avapeek:demo-clear` removes it again, so you can try Avapeek before committing to it. `./up.sh --demo` does this as part of the install
- Documentation for letting your family reach a self-hosted install from outside your home: Cloudflare Tunnel, Cloudflare Access, a VPN or your own reverse proxy, with the trade-offs of each

## [0.1.1] - 2026-11-02

### Changed

- The app's favicon now uses the full Avapeek logo, matching the installed app icon
- Release images are built on native amd64 and arm64 runners instead of under emulation, and a release is only published once both architectures are available for every image
- Documentation is published at [avapeek.com](https://avapeek.com), including a getting started guide for invited family members

### Fixed

- On iPhone and iPad, Settings now shows Notifications in a Safari tab too, with a hint to add Avapeek to the Home Screen first, instead of hiding it
- Logged-out API requests without a JSON `Accept` header return a 401 instead of a 500

## [0.1.0] - 2026-11-02

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
- Clear waiting state for new members until an admin shares the album key, with a "Remind admins" button, admin badges and banner, and access requested / access granted push notifications
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
- Strict Content-Security-Policy and other security headers on the bundled Caddy, with the storage origin derived automatically for external S3 providers
- Security reports go through private vulnerability reporting

### Known limitations

- Some metadata is plaintext: child names, photo dates, sizes and types, membership, and comment timing
- No key rotation when a member is removed or leaves; removal revokes app access, not cryptographic access
- No password recovery, and the cryptography has not been independently audited
- See [docs/security.md](docs/security.md) and [docs/roadmap.md](docs/roadmap.md) for details
