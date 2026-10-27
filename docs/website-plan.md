# avapeek.com website plan

Status: plan only. Nothing here is built yet.

## Goals

1. Explain what Avapeek is and why it exists in under a minute.
2. Get a technical visitor from landing page to a running install.
3. Give non-technical visitors somewhere to go: the hosted-version waitlist ("coming soon").
4. Make the security story credible: publish the design and its limits, not just marketing.

Audience: parents and relatives (landing, why, features, waitlist), self-hosters (docs, install), security reviewers (security, architecture).

## Site map

| Path | Page | Purpose | Source |
| --- | --- | --- | --- |
| `/` | Home / landing | Pitch, 3 key points (private, yours, family-only), screenshots, install snippet, waitlist call to action, GitHub link | New, drawing on README intro and "Why Avapeek?" |
| `/why` | Why Avapeek? | Comparison with social media, shared albums, family apps; honest trade-offs | `docs/why-avapeek.md` as is |
| `/features` | Features | Feature tour with screenshots: encryption, per-child families and roles, feed, tags, memories, comments, export, PWA, push, 2FA | README feature list, `docs/architecture.md`; screenshots new |
| `/docs/` | Docs index | Entry point to the guides | New, short |
| `/docs/installation` | Install (self-hosting) | `up.sh` quickstart, requirements, TLS, upgrades, backups, uninstall | `docs/installation.md` |
| `/docs/configuration` | Configuration | Every env var | `docs/configuration.md` |
| `/docs/storage` | Storage | Garage, S3, B2, R2, CORS, quota | `docs/storage.md` |
| `/docs/security` | Security | Key hierarchy, what the server sees, threat model, limitations | `docs/security.md` |
| `/docs/architecture` | Architecture | Components, upload/view/export flows, PWA, push | `docs/architecture.md` |
| `/docs/development` | Development | Dev environment and tests (for contributors) | `docs/development.md` |
| `/faq` | FAQ and troubleshooting | | `docs/faq.md` |
| `/hosted` | Hosted version (coming soon) | Short explanation, what it will and will not be, email waitlist | New |
| `/security-policy` | Reporting vulnerabilities | Link/mirror of the policy | `SECURITY.md` |
| `/changelog` | Releases | Release notes | Needs a `CHANGELOG.md` (does not exist yet) |
| `/privacy` | Privacy policy of the website itself | Required once a waitlist collects email addresses | New |
| external | GitHub | Header and footer link to `github.com/springledev/avapeek` (confirm final org/repo name first) | n/a |

Header nav: Why, Features, Docs, FAQ, Hosted (coming soon), GitHub. Footer: License (AGPL-3.0), Security, Privacy, Changelog.

## What can be reused

All of `docs/*.md` is already plain Markdown with relative links between files, so it can be published nearly unchanged.

- **Reuse as is:** installation, configuration, storage, security, architecture, development, faq, why-avapeek.
- **Generate or sync, do not copy by hand:** have the site build pull `docs/*.md` from the main repo (git submodule, a CI step that checks out the repo, or a `srcDir` pointing at it) so the site never drifts from the code. Inter-doc links like `security.md#password-loss` map cleanly to site routes.
- **Reuse with light edits:** README "Quickstart" for the landing page snippet; README "Features" for the features page intro.
- **Generated reference candidates:** the env-var table could be generated from `backend/.env.production.example` and `backend/config/avapeek.php` so `configuration.md` stays correct; the API routes table from `php artisan route:list --json`.
- **Do not publish:** `AGENTS.md`, `PLAN-v0.2.md`, `opencode.json` (internal), and `backend/README.md` / `frontend/README.md` (framework boilerplate).

## Tooling recommendation

**VitePress** (recommendation, not a decision). Reasons:

- The project is already Vue, so the team can write custom components (waitlist form, screenshot gallery, feature cards) with no new skills.
- Markdown-first, so the existing docs drop in; built-in sidebar, search (local search needs no third-party service), dark mode, and fast static output.
- Static output is trivial to host on any static host, which fits the privacy message (no tracking scripts needed).

Alternatives: Astro Starlight (good docs plus flexible marketing pages), Docusaurus (heavier, React). Whichever is chosen, keep the marketing pages and the docs in one site and one repo path.

Hosting notes: a static host or the project's own server. Avoid analytics or third-party embeds, or use a privacy-respecting self-hosted option and say so in the privacy page. The waitlist needs a tiny backend or a form service; prefer something that stores only an email address and a timestamp, with a double opt-in.

Suggested repo layout: a `website/` directory in the main repo (so docs and site change together), or a separate `avapeek-website` repo that pulls `docs/` at build time. Pick one; the first is simpler.

## Content gaps to fill

1. Screenshots and a short screen recording (README says "to be added"): feed, upload, invite flow, child settings, mobile PWA install.
2. Landing page copy and a one-paragraph "how it works" with a simple diagram of the key hierarchy (password to key to child key to photo key).
3. Hosted version page and waitlist: what it will be, pricing intentions (if decided), and a statement that hosted users must trust the operator not to serve modified code (see the threat model).
4. A non-technical "Getting started for families" page: how an invited grandparent signs up, installs the PWA to the home screen, and what happens if they forget their password.
5. "How does end-to-end encryption work" plain-language explainer (security.md is accurate but technical).
6. Changelog / release notes and a version badge (no CHANGELOG or tags exist yet).
7. Privacy policy and contact address for the website itself.
8. A roadmap page (derive a public version from internal planning, without internal tooling details).
9. A comparison "FAQ" entry set (draft exists in why-avapeek.md); legal review of any competitor wording before launch.
10. Open Graph image, favicon, logo usage guidance. Logo and icons exist in `frontend/public/`; icon files may still be placeholders (`generate-placeholder-icons.mjs`).
11. Confirm domain, GitHub org name and contact email; replace `security@example.invalid`.

## Ordered task list

1. Decide final names: domain, GitHub org/repo, security and contact email.
2. Capture screenshots (light and dark, mobile and desktop) using seeded demo data with no real children or faces.
3. Scaffold VitePress in `website/` with the sitemap above and the theme tokens from `frontend/src/**/tokens.css`.
4. Wire `docs/*.md` into the site build; fix link rewriting; verify every page renders.
5. Write the landing page, features page and "Getting started for families".
6. Add the hosted/waitlist page and form (double opt-in), plus the privacy page.
7. Add `CHANGELOG.md` and a releases page; tag the first release.
8. Add CI to build the site on every PR and deploy on merge to master.
9. Add social metadata (OG image, descriptions), sitemap.xml, robots.txt.
10. Accessibility and performance pass (Lighthouse, keyboard navigation, dark mode).
11. Launch together with the open-source release (see [release-checklist.md](release-checklist.md)).
