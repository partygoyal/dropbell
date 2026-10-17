# Dropbell API

## Admin workspace

Open `http://127.0.0.1:8000/admin/login`. The dashboard shows MySQL-backed catalogue, customer, order and paid-value totals. Session authentication, CSRF protection, login throttling and an administrator role check protect access. Logout uses POST and invalidates the session.

Create additional administrators with `php artisan admin:create email@example.com` (hidden password prompt) or add `--generate` for a generated initial password. Existing accounts are never overwritten. No public admin registration is exposed. Credentials are not stored in this README.

Laravel backend for the Next.js storefront in `../homepage`.

## Local run

The ignored `.env` is configured for MySQL at `127.0.0.1:3306`, database `dropbell`, user `root`, empty password. Adjust it for your local setup.

```sh
composer install
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

## Available endpoints

- `GET /api/health`: database connectivity.
- `GET /api/products`: catalogue; optional `category` and `q` filters.
- `GET /api/products/{id}`: one product.

Prices use integer `price_paise`. Catalogue seeding adds missing products without overwriting existing rows. CORS permits the Next.js local origins on port 3000.

Users, addresses, orders, and order items have database tables. Authentication and their write endpoints are not implemented yet. Next.js continues using its existing demo storage until integrated; the fixed frontend OTP is not backend authentication.

## Tests

`php artisan test` runs against isolated in-memory SQLite, not the MySQL database.
