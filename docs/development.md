# Development

## Stack

Backend: PHP 8.5, Laravel 13, Sanctum, Pest 4, Pint. Frontend: Vue 3.5, TypeScript, Pinia, Tailwind 4, Vitest. Infrastructure: PostgreSQL 18, Redis 8, Garage (S3), Mailpit.

## Repository layout

```
backend/                 Laravel API (app/, routes/api.php, database/migrations, tests/)
frontend/                Vue SPA
  src/crypto/            Encryption helpers. READ-ONLY, see below
  src/stores/            Pinia stores
  src/composables/       Reusable logic (e.g. useLazyDecrypt)
  src/__tests__/         Vitest specs
docker/                  Dockerfiles and configs: php/, node/, nginx/, caddy/, garage/
docker-compose.dev.yml   Dev dependencies (+ backend with a bind mount)
docker-compose.prod.yml  Production stack (builds from source)
docker-compose.release.yml  Adds ghcr.io images on top of prod
up.sh                    Installer / upgrader
docs/                    This documentation
```

## The crypto rule

`frontend/src/crypto/**` is read-only. These files are reviewed by hand and must not be changed. New code imports and calls the existing helpers, for example `import { encryptString, decryptString } from '@/crypto/encrypt'`. The server must only ever receive ciphertext for user content (`x_encrypted` + `iv_x` columns). Never add plaintext columns for photo or comment content.

## Dev environment

```sh
cp .env.dev.example .env
cp backend/.env.dev.example backend/.env
# generate APP_KEY: echo "base64:$(openssl rand -base64 32)"  -> backend/.env
# use the bundled Garage for object storage:
echo 'COMPOSE_PROFILES=garage' >> .env

docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec backend php artisan migrate

cd frontend && npm install && npm run dev
```

What you get:

| Service | URL |
| --- | --- |
| Frontend (Vite, proxies `/api` to the backend) | http://localhost:5173 |
| Backend API (FrankenPHP, bind-mounted `./backend`) | http://localhost:8000 |
| Garage S3 | http://localhost:3900 |
| Mailpit UI | http://localhost:8025 |
| PostgreSQL / Redis | localhost:5432 / localhost:6379 |

The dev backend container overrides `DB_HOST`, `REDIS_HOST` and the S3 settings so they use compose service names; see `docker-compose.dev.yml`. If you prefer running PHP on the host, use `composer run dev` inside `backend/` (it starts `artisan serve`, a queue listener, `pail` and Vite) against the compose-provided PostgreSQL, Redis and Garage.

Presigned photo URLs in dev point at `AWS_TEMPORARY_URL` (default `http://localhost:8000`), which proxies `/photos/*` to Garage. Run `php artisan storage:check` to verify storage.

## Tests and checks

Backend (from `backend/`, or via `docker compose exec backend`):

```sh
php artisan test --compact                       # whole Pest suite
php artisan test --compact --filter=PhotoTest    # a subset
vendor/bin/pint --format agent                   # fix code style
```

Frontend (from `frontend/`):

```sh
npm run type-check
npm run lint
npm run test:unit -- --run
```

Every change needs a test. Use factories in backend tests. Mock crypto where needed in frontend tests.

## Conventions

- Backend: use `php artisan make:*` generators, explicit return types, Eloquent API resources/existing response shapes. Check sibling files for style.
- Frontend: Composition API with `<script setup lang="ts">`, 48px touch targets, blur-up image loading via `useLazyDecrypt`.
- Authorization: check membership via `child_photo` on every photo-related request. Photo, comment and tag operations verify membership through `child_photo` and the user's children. No IDOR.
- Tests: backend changes need a Pest test, frontend changes need a Vitest spec in `frontend/src/__tests__/`.
- Checks before committing: backend `vendor/bin/pint` and the Pest suite; frontend `npm run type-check && npm run lint && npm run test:unit -- --run`.
- Dependencies: do not add new ones without a deliberate decision.
- Commit messages: short and lowercase, past tense, e.g. `fixed tag scoping for shared photos`.

## Building images locally

```sh
docker compose -f docker-compose.prod.yml build
```

or `./up.sh --build`. Release images are built by `.github/workflows/release.yml` when a `v*` tag is pushed.
