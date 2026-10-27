# Open-source release readiness checklist

Audit date: 2026-10-27, from a read-only inspection of the working tree and git history (151 commits, 2 author identities). Items marked done need no action.

## Blockers (fix before making the repository public)

- [ ] **Backend test suite fails locally: 54 errors out of 610 tests.** All are `Class "Minishlink\WebPush\VAPID" not found` in `PushNotificationTest` and `PushKeys`. `minishlink/web-push` is in `composer.json` and `composer.lock` but not installed in `backend/vendor` here, so this may just be a stale local `vendor/` (run `composer install`), but confirm on a clean checkout. Other agents were editing push code during the audit, so re-run once the tree is stable.
- [ ] **No CI for the backend.** `.github/workflows/ci.yml` runs only frontend type-check, lint and Vitest. The Pest suite and Pint are never run on pull requests, and the comment in the file says so. Add a backend job (PHP 8.5, Composer cache, Pint `--test`, Pest) so contributors get feedback and regressions are caught.
- [ ] **`SECURITY.md` has a placeholder contact.** `security@example.invalid` and a "TODO (maintainer)" line. Enable GitHub private vulnerability reporting and/or set a real address.
- [ ] **Decide the final GitHub org/repo name.** `springledev/avapeek` is hard-coded in `README.md`, `docs/installation.md`, `up.sh`, `docker-compose.release.yml`, `.env.production.example`, `.github/workflows/release.yml` and `SECURITY.md`. If the public repo or GHCR namespace differs, every one breaks the quickstart.
- [ ] **No release exists, but the default install path needs one.** `up.sh` pulls `ghcr.io/springledev/avapeek-*:latest`. There are no git tags. Until a `v*` tag is pushed and `release.yml` has published images (and the GHCR packages are set to public), the quickstart fails for everyone except `--build`. Tag `v0.1.0` (or similar), run the release workflow, verify an anonymous `docker pull`, and test `./up.sh` on a clean machine (amd64 and arm64).
- [ ] **Git author identities.** History contains two names ("Daniel", "Mattman") with GitHub noreply emails for two different GitHub accounts (`dpneal123`, `fartdotcloud`). Not a secret, but decide whether you want those handles public; rewriting history is only practical before publishing.
- [ ] **Run a final secrets scan on the exact commit you publish.** The manual scan found nothing (see "Verified clean" below), but a tool such as `gitleaks` or `trufflehog` over all history is the standard sign-off and cheap.
- [ ] **Independent review of the crypto design is not done** and the docs say so ("Not audited"). That is acceptable for an open release if the README stays upfront about it (it does), but see the "Should do" items on known weaknesses before promoting it as a privacy product.

## Should do

Release hygiene
- [ ] **Add `CODE_OF_CONDUCT.md`** (Contributor Covenant) and link it from `CONTRIBUTING.md`.
- [ ] **Add `.github/` issue templates (bug, feature) and a PR template** with the checks from `CONTRIBUTING.md` and the "ciphertext only / authorize every request" reminders. Add `config.yml` pointing security reports to `SECURITY.md`.
- [ ] **Add `CHANGELOG.md`** and a versioning policy. Versions are inconsistent: `frontend/package.json` is `0.0.0`, `composer.json` has no version, docs mention `AVAPEEK_VERSION=0.2.0`. Choose SemVer, set the version in one place, and make the release workflow create a GitHub Release with notes.
- [ ] **Replace the boilerplate sub-READMEs.** `backend/README.md` is the stock Laravel README (Laravel logo, "About Laravel", sponsors); `frontend/README.md` is the Vite template text. Replace each with a pointer to the root README and `docs/development.md`. `backend/composer.json` still has `"name": "laravel/laravel"`; rename it (for example `springledev/avapeek`) and set a real description.
- [ ] **Screenshots for the README.** It says "Screenshots: _to be added._". Use seeded demo data with no real people, in light and dark, mobile and desktop.
- [ ] **Add the hosted-version mention and website link** to the README once the domain is decided (README now carries a "Hosted version coming soon" note).
- [ ] **Add a `docker compose` smoke test to CI** (build or pull, `up`, wait for `/up`, run `storage:check`). The install path is the most likely thing to break for newcomers.
- [ ] **Add Dependabot or Renovate** for Composer, npm, GitHub Actions and Docker base images. Actions are already pinned by SHA, which is good.
- [ ] **Docker images are pinned to exact tags** (FrankenPHP, node, nginx, Caddy, Postgres, Redis, Garage); consider pinning digests and let Dependabot/Renovate bump them. Check that `docker/garage` init scripts do not log secrets.
- [ ] **Branch protection and required checks** on `master` once CI covers both suites.

Internal files that should not ship (flag only, nothing was removed)
- [ ] `AGENTS.md` (root, 163 lines) and `backend/AGENTS.md` (102 lines) are **tracked**. They contain the internal agent model roster, orchestration workflow and `../avapeek-features/` paths. Either remove them from the public repo or rewrite them as neutral contributor guidance (the engineering rules in them duplicate `CONTRIBUTING.md` and are fine to keep).
- [ ] `backend/.agents/skills/**` (laravel-best-practices, pest-testing; 20+ files) and `backend/boost.json` are **tracked**: tooling content, not part of the product. One of the rule files contains an example string `sk_live_abc123`, which will trip secret scanners (it is documentation, not a credential). Remove from the public tree or exclude.
- [ ] `up.sh` is **tracked and intended** (it is the installer; documented). Keep, but see the version/URL items above. (The brief called it internal; per the README and docs it is the supported install path.)
- [ ] `opencode.json` and `PLAN-v0.2.md` exist locally but are **git-ignored** (`.gitignore`: `PLAN*.md`, `opencode.json`), so they will not ship. Same for `.claude/` and `.env`. Check that no earlier commit contained them (see below).
- [ ] `.gitignore` comment "private/local agent tooling (not published)" is fine to keep or reword.

Docs and accuracy
- [ ] **README security paragraph was stale** (said the password is sent to the server and that tag names are plaintext). Fixed during this task to match `docs/security.md` (auth hash, encrypted tag names). Re-read the rest of the README against `docs/installation.md` once more before release.
- [ ] **Verify every command in `docs/installation.md` on a clean VM**, including the upgrade, backup restore and uninstall sections, and `./up.sh --storage external`.
- [ ] **Documentation for restoring a backup end to end** (database dump plus bucket) with a tested example.
- [ ] **Roadmap / known-issues page** so contributors know what is welcome (key rotation on member removal, ciphertext-to-photo binding via AAD, Argon2id, Content-Security-Policy, invite key fingerprint verification are all listed as limitations in `docs/security.md`).
- [ ] **Crypto design doc completeness.** `docs/security.md` is thorough (key hierarchy, wrap format, metadata table, threat model, limitations). Gaps to close before promotion: a formal description of the auth-hash/login protocol and rate-limiting, the PBKDF2 parameters policy and how they can be upgraded, how browsers' random IV uniqueness bound is respected for per-photo keys (random 96-bit IV per object under a per-photo key is fine; say so), and a versioning scheme for the ciphertext formats so future migration is possible.
- [ ] **Content-Security-Policy.** Docs state the app sets none while the KEK sits in `localStorage`. Add a strict CSP in the nginx/Caddy config; it is the single biggest hardening win available.
- [ ] **Test coverage for the crypto helpers.** `keychain.spec.ts` and `auth-hash.spec.ts` exist; check `encrypt.ts` and `thumbnails.ts` for known-answer tests and wrap/unwrap round trips (add new test files only, no edits to the read-only crypto source).
- [ ] **TODO/FIXME scan:** only one marker in first-party code, the `SECURITY.md` placeholder above.

Licensing
- [ ] **Project license is AGPL-3.0-or-later** (`LICENSE` is the full text; `composer.json` and `package.json` both declare it; the container image label is set). Consistent. No per-file SPDX headers exist; optional, but add a short `SPDX-License-Identifier` convention if you want it.
- [ ] **Dependency licenses are compatible.** Frontend production dependencies (from `package-lock.json`): MIT (175), MPL-2.0 (12), ISC (10), BSD (5), Apache-2.0 (2), 0BSD (1), and **LGPL-3.0 (1: `heic-to`)**. LGPL-3.0 and MPL-2.0 are compatible with AGPL-3.0; note that `heic-to` is loaded as a lazy chunk, which is fine. Backend: MIT, BSD, Apache-2.0, plus `nette/schema` and `nette/utils` (dual BSD-3-Clause / GPL-2.0 / GPL-3.0, usable under BSD). No incompatible license found. Add a `THIRD-PARTY-NOTICES` file or generate one in the release build.
- [ ] **Contributor licensing.** `CONTRIBUTING.md` says contributions are AGPL. Consider a DCO sign-off or CLA if you may later offer a dual license or the hosted service under different terms.
- [ ] **Trademark and naming.** Check that "Avapeek" and the logo do not clash with an existing mark before the website and hosted service launch.

Product risks to be honest about at release
- [ ] The hosted-version trust caveat (operator can serve modified JS) should be stated wherever the hosted service is advertised.
- [ ] Mention the plaintext metadata and no-key-rotation limitations in release notes, not only deep in `docs/security.md`.

## Nice to have

- [ ] Demo instance or a short screen recording; a seed command for demo data.
- [ ] GitHub Discussions enabled; `SUPPORT.md` explaining where to ask questions.
- [ ] `CITATION`/`FUNDING.yml` if you want sponsorship.
- [ ] Reproducible-build notes and signed release images (cosign).
- [ ] SBOM published with each release.
- [ ] Translations / i18n plan.
- [ ] Automated accessibility checks and an end-to-end browser test (Playwright) of register, upload, invite and view.
- [ ] Public roadmap board.
- [ ] Add `docs/website` build to CI once the website exists (see [website-plan.md](website-plan.md)).

## Verified clean

- **Secrets in the working tree:** `.env` files are ignored (`.gitignore: .env`); only `*.example` env files are tracked and contain placeholders/empty values. No private keys, cloud keys or tokens found by pattern search (`BEGIN ... PRIVATE KEY`, `AKIA...`, `ghp_`, `xox`, `sk_live`) other than the documentation example above.
- **Secrets in history:** no `.env`, `.pem`, `.key`, `.sqlite`, `.sql`, `.zip` or photo files were ever added in any commit (checked with `git log --all --diff-filter=A --name-only`). No `APP_KEY=base64:...` value was ever committed.
- **Personal data:** no photos or test images in the repo (only app icons in `frontend/public/`). No personal hostnames or private IPs outside test fixtures for SSRF protection (`10.0.0.x`, `192.168.x.x` in push endpoint tests, which are intentional). Your email address does not appear in tracked files. Git history contains two noreply emails (see Blockers).
- **Frontend checks (run during the audit):** `npm run type-check` passes; `npm run lint` passes with 0 warnings and 0 errors (the script runs with `--fix`, no files were changed by it); `npm run test:unit -- --run` passes: 56 files, 431 tests.
- **Backend checks:** 556 of 610 tests pass; the 54 failures are all the missing `Minishlink\WebPush\VAPID` class described above.
- **Telemetry:** no analytics, error-reporting or tracking SDK found in `frontend/src`, `backend/app`, `backend/config` or `docker/`.
- **Actions:** workflow actions are pinned to commit SHAs with minimal `permissions`.
