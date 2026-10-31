# Open-source release readiness checklist

Audit date: 2026-10-27, updated 2026-10-31. Avapeek is released as open source with a single maintainer; outside contributions are not accepted for now, so contributor-facing items (contributing guide, code of conduct, PR template, CLA/DCO) are intentionally out of scope.

## Blockers (fix before making the repository public)

- [x] **Backend test suite.** The 54 `Minishlink\WebPush\VAPID` errors were a stale local `vendor/`. After `composer install` all 625 tests pass.
- [x] **Backend CI.** `.github/workflows/ci.yml` now runs Pint and Pest (PHP 8.5, Composer cache) alongside the frontend checks.
- [x] **`SECURITY.md` contact.** Points to GitHub private vulnerability reporting.
- [ ] **Enable private vulnerability reporting** in the GitHub repository settings (Security → Private vulnerability reporting), otherwise the `SECURITY.md` link has nowhere to go. *Manual.*
- [x] **Final GitHub org/repo name.** Kept as `springledev/avapeek`.
- [ ] **Patch vulnerable backend dependencies.** `composer audit` reports 31 advisories in 9 production packages, including a critical one in `laravel/framework` (fixed in 13.29.0; locked at 13.15.0), plus high in `guzzlehttp/guzzle`, `league/commonmark` and `symfony/http-foundation`. Run `composer update` within the existing constraints and re-run the suite.
- [ ] **Publish the first release.** `up.sh` pulls `ghcr.io/springledev/avapeek-*:latest` and no tag exists yet. Set the date in the `CHANGELOG.md` 0.1.0 heading, tag `v0.1.0`, let `release.yml` publish images and the GitHub Release, set the GHCR packages to public, verify an anonymous `docker pull`, and test `./up.sh` on a clean machine (amd64 and arm64). *Manual.*
- [x] **Git author identities.** History rewritten to a single `springledev` identity (backup branch `backup/pre-author-rewrite`). Requires a force-push to existing remotes. *Manual: push.*
- [x] **Secrets scan.** `gitleaks` over full history: 6 hits, all dummy dev/test values or a documentation example, now listed in `.gitleaksignore`; rescan is clean. Re-run on the exact commit you publish.
- [ ] **Independent review of the crypto design** is not done and the docs say so. Acceptable for an open release while the README stays upfront about it.

## Should do

Release hygiene
- [x] **Issue templates.** Bug report form plus `config.yml` routing security reports to private reporting. No PR template.
- [x] **`CHANGELOG.md` and versioning.** SemVer, the `vX.Y.Z` tag is the source of truth, `frontend/package.json` is 0.1.0, and `release.yml` checks the tag matches and creates a GitHub Release from the changelog section.
- [x] **Boilerplate sub-READMEs replaced**, and `backend/composer.json` renamed to `springledev/avapeek` with a real description.
- [ ] **Screenshots for the README** ("Screenshots: _to be added._"). Use seeded demo data with no real people, light and dark, mobile and desktop. *Manual.*
- [ ] **Hosted-version mention and website link** in the README once the domain is decided.
- [x] **Docker compose smoke test in CI** (builds from source with `up.sh --build`, waits for `/up`, runs `storage:check`, checks the SPA returns 200). Not yet run on GitHub; check the first run.
- [x] **Dependabot** for Composer, npm, GitHub Actions, Dockerfiles and compose images (weekly, minor/patch grouped).
- [ ] **Pin Docker image digests** and let Dependabot bump them; check that `docker/garage` init scripts do not log secrets.
- [ ] **Branch protection and required checks** on `master` (frontend, backend, compose-smoke). *Manual, GitHub settings.*

Internal files that should not ship
- [x] `AGENTS.md`, `backend/AGENTS.md`, `backend/.agents/**` and `backend/boost.json` are untracked and git-ignored (still on disk). They remain in **history**; rewrite history to drop them if that matters.
- [x] `up.sh` is tracked and intended (the installer).
- [x] `opencode.json`, `PLAN*.md`, `.claude/` and `.env` are git-ignored and were never committed.

Docs and accuracy
- [ ] **Re-read the README against `docs/installation.md`** once more before release.
- [ ] **Verify every command in `docs/installation.md` on a clean VM**, including upgrade, backup restore, uninstall and `./up.sh --storage external`. *Manual.*
- [ ] **Document restoring a backup end to end** (database dump plus bucket) with a tested example. *Needs a real restore test.*
- [x] **Roadmap / known-issues page** (`docs/roadmap.md`).
- [x] **Crypto design doc gaps closed** in `docs/security.md`: login/auth-hash protocol and rate limits, PBKDF2 parameters and planned upgrade path, IV uniqueness (content IVs are random 16-byte, wraps 12-byte), ciphertext formats and planned versioning.
- [x] **Content-Security-Policy.** Strict CSP and tighter security headers on the bundled Caddy; the storage origin for external S3 is derived by the backend at start (`storage:csp-origin`). HEIC decoding switched to heic-to's CSP-safe build.
- [ ] **Browser-test the CSP** on a staging or local prod stack: login, feed, viewer, HEIC upload, TOTP QR, ZIP export, push opt-in, and external S3 upload/view. Watch DevTools for "Refused to …". *Manual.*
- [x] **Crypto helper tests.** Known-answer, round trip and tamper tests for `encrypt.ts`, plus `thumbnails.ts` tests (`encrypt.spec.ts`, `thumbnails.spec.ts`).
- [x] **TODO/FIXME scan:** the only marker was the `SECURITY.md` placeholder, now fixed.

Licensing
- [x] **Project license is AGPL-3.0-or-later**, declared consistently. Per-file SPDX headers are optional.
- [x] **Dependency licenses are compatible** and listed in `THIRD-PARTY-NOTICES.md` (316 production components; `heic-to` LGPL-3.0 as a lazy chunk, `nette/*` used under BSD-3-Clause). Regenerate before each release.
- [ ] **Trademark and naming.** Check that "Avapeek" and the logo do not clash with an existing mark before the website and hosted service launch. *Manual.*

Product risks to be honest about at release
- [ ] The hosted-version trust caveat (operator can serve modified JS) should be stated wherever the hosted service is advertised.
- [x] Plaintext metadata, no key rotation and no audit are listed under "Known limitations" in the 0.1.0 changelog, which becomes the release notes.

## Nice to have

- [ ] Demo instance or a short screen recording; a seed command for demo data.
- [ ] GitHub Discussions or a `SUPPORT.md` explaining where to ask questions.
- [ ] `FUNDING.yml` if you want sponsorship.
- [ ] Reproducible-build notes and signed release images (cosign).
- [ ] SBOM published with each release.
- [ ] Translations / i18n plan.
- [ ] Automated accessibility checks and an end-to-end browser test (Playwright) of register, upload, invite and view.
- [ ] Add `docs/website` build to CI once the website exists (see [website-plan.md](website-plan.md)).

## Verified clean

- **Secrets in the working tree and history:** only `*.example` env files are tracked; no `.env`, `.pem`, `.key`, `.sqlite`, `.sql`, `.zip` or photo files were ever committed; `gitleaks` is clean with the documented ignores.
- **Personal data:** no photos or test images (only app icons). Private IPs appear only in SSRF test fixtures. Git history uses a single noreply identity.
- **Checks (2026-10-31):** frontend type-check, lint, build and 464 Vitest tests pass; backend Pint and 632 Pest tests pass.
- **Telemetry:** no analytics, error-reporting or tracking SDK.
- **Actions:** pinned to commit SHAs with minimal `permissions`.
