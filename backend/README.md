# Avapeek API

The Laravel 13 JSON API for Avapeek. It stores only ciphertext and wrapped keys, never plaintext photo or comment data.

For setup, see the root [README](../README.md) and [docs/development.md](../docs/development.md).

## Commands

Run from `backend/`:

```sh
php artisan test --compact                    # Pest suite
php artisan test --compact --filter=PhotoTest # a subset
vendor/bin/pint                               # fix code style
php artisan migrate                           # run database migrations
```
