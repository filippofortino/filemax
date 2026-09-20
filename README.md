# Laravel Starter Kit

A compact Laravel starter template with Inertia v3, React, TypeScript, SSR,
Tailwind CSS, Wayfinder, Octane configuration, and a strict quality toolchain.

The template is intentionally small: one Inertia page, one web route, SQLite by
default, and scripts for building, testing, linting, and local development.

## Stack

- PHP 8.5 and Laravel 13
- Inertia Laravel 3 and `@inertiajs/react` 3
- React 19 and TypeScript
- Tailwind CSS 4
- Laravel Wayfinder for typed route and controller helpers
- Laravel Octane configuration
- Pest 5, Pest Browser, Larastan, Pint, Rector, and Vite+
- SQLite as the default local database

## Requirements

- PHP 8.5
- Composer 2
- Bun 1.3 or newer
- Node.js 24 when running Vite+ outside Bun-managed environments
- SQLite, MySQL, MariaDB, PostgreSQL, or SQL Server

## Quick Start

Install dependencies and prepare the application:

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
bun install
bun run build
```

The project also includes a setup shortcut:

```bash
touch database/database.sqlite
composer run setup
```

Update `APP_NAME`, `APP_URL`, and any database settings in `.env` before you
start building your application. If you are not using SQLite, configure your
database connection before running migrations.

## Development

Run the full local development stack:

```bash
composer run dev
```

This starts the Laravel development server, queue listener, Laravel Pail logs,
and the Vite development server in one process group.

If you use Laravel Herd for PHP, you can let Herd serve the application and run
only the frontend dev server when needed:

```bash
bun run dev
```

## Testing and Quality

Run the complete CI-style test suite:

```bash
composer test
```

Useful focused commands:

```bash
php artisan test --compact
composer test:unit
composer test:lint
composer test:types
composer lint
bun run test:lint
```

`composer test` runs type coverage, PHP test coverage, PHP formatting and
refactor checks, frontend formatting checks, and static analysis.

## Frontend

Frontend code lives in `resources/scripts`.

- `resources/scripts/app.tsx` boots the Inertia React app.
- `resources/scripts/pages/index.tsx` is the starter home page.
- `resources/css/app.css` imports Tailwind CSS and declares source paths.
- The `@` alias points to `resources/scripts`.

The production build creates both the browser bundle and the SSR bundle:

```bash
bun run build
```

Inertia SSR is enabled by default in `config/inertia.php`. The test environment
disables SSR in `phpunit.xml` for faster and simpler test runs.

## Backend

The starter route is defined in `routes/web.php`:

```php
Route::get('/', fn (): Response => Inertia::render('index'))->name('home');
```

Shared Inertia behavior lives in
`app/Http/Middleware/HandleInertiaRequests.php`. Application bootstrapping and
middleware registration live in `bootstrap/app.php`.

## Wayfinder

Wayfinder is configured in `vite.config.ts` with output under
`resources/scripts`. Import generated helpers from `@/actions` or `@/routes`
when wiring frontend components to Laravel controllers and named routes.

## Octane

Octane configuration is included in `config/octane.php`. Set `OCTANE_SERVER`
when you are ready to run the app under `roadrunner`, `swoole`, or `frankenphp`.

When adding services for Octane, avoid request-specific state in singletons or
static properties. Prefer scoped bindings for request-aware services.

## CI

GitHub Actions is configured in `.github/workflows/tests.yml`. The workflow
installs PHP, Composer dependencies, Bun dependencies, builds assets, installs
Playwright browsers, and runs `composer test`.

## Template Checklist

After creating a new project from this starter kit:

1. Rename the project in `composer.json`.
2. Update `APP_NAME`, `APP_URL`, mail settings, and database settings in `.env`.
3. Replace the starter home page in `resources/scripts/pages/index.tsx`.
4. Add your routes in `routes/web.php`.
5. Add feature tests for new user-facing behavior.
6. Run `composer test` before opening a pull request.
