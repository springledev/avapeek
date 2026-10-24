# Contributing to Avapeek

Thanks for helping. Please keep changes small and focused, and open an issue first for anything large.

## Setup

See [docs/development.md](docs/development.md) for the dev environment, repo layout and test commands.

## Rules

1. **Tests are required.** Backend changes need a Pest test (`php artisan test --compact --filter=...`), frontend changes need a Vitest test in `frontend/src/__tests__/`.
2. **Checks must pass before you open a PR:**
   - Backend: `vendor/bin/pint --format agent` and the Pest suite.
   - Frontend: `npm run type-check && npm run lint && npm run test:unit -- --run`.
3. **The server must only ever receive and store ciphertext** for user content. Encrypted columns follow the `x_encrypted` + `iv_x` pattern. Do not add plaintext columns for photo or comment content.
4. **Authorization on every request.** Photo, comment and tag operations must verify membership through `child_photo` and the user's children. No IDOR.
5. **Do not edit `frontend/src/crypto/**`.** Those files are reviewed by hand. If you believe they need a change, open an issue describing it. New code calls the existing helpers (`encryptString`, `decryptString`, `encryptBuffer`, `decryptBuffer` from `@/crypto/encrypt`).
6. No new dependencies without discussing them in the PR.
7. Commit messages: short and lowercase, e.g. `fix tag scoping for shared photos`.

## Security issues

Report them privately, see [SECURITY.md](SECURITY.md).

## License

Avapeek is licensed under the AGPL-3.0-or-later. By contributing you agree that your contribution is licensed under the same terms. The AGPL requires that anyone who runs a modified version as a network service makes the modified source available to its users.
