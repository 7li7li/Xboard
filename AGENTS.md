# Repository Guidelines

## Project Structure & Module Organization

Xboard is a Laravel 12 application. Core PHP code lives in `app/`, routes in `routes/`, configuration in `config/`, and migrations, factories, and seeders in `database/`. Blade views, Sass, localization files, and rule templates are under `resources/`. Public web assets belong in `public/`; the packaged frontend theme is in `theme/Xboard/`. Built-in plugins live in `plugins-core/`, while installable or local plugins should use `plugins/`. Tests are split into `tests/Feature/` and `tests/Unit/`.

## Build, Test, and Development Commands

- `composer install` installs PHP dependencies and runs Laravel package discovery.
- `cp .env.example .env && php artisan key:generate` creates a local environment file and app key.
- `php artisan migrate --seed` applies database schema and seed data for development.
- `php artisan serve` runs the Laravel HTTP server for local checks.
- `php artisan test` runs the PHPUnit test suite.
- `./vendor/bin/phpstan analyse` runs Larastan static analysis using `phpstan.neon`.
- `docker compose -f compose.sample.yaml up -d` starts the sample Docker stack; see other `compose.*.sample.yaml` files for alternate layouts.

## Coding Style & Naming Conventions

Follow `.editorconfig`: UTF-8, LF line endings, four-space indentation, final newline, and trimmed trailing whitespace. YAML uses two spaces. Use PSR-4 namespaces from `composer.json`: `App\` for `app/`, `Plugin\` for `plugins/`, and `Tests\` for `tests/`. Name service classes by responsibility, for example `LoginService`, and end test classes with `Test`.

## Testing Guidelines

Use PHPUnit through Laravel's test runner. Place HTTP and integration behavior in `tests/Feature/`; place isolated service or utility coverage in `tests/Unit/`. Mirror the source namespace in test paths where practical, such as `tests/Unit/Services/Auth/RegisterServiceTest.php`. Add tests for authentication, billing, plugin hooks, migrations, and protocol/server behavior because those paths affect access or runtime compatibility.

## Commit & Pull Request Guidelines

Recent history uses short imperative subjects and Conventional Commit prefixes where helpful, such as `feat: add admin user plugin hooks`, `fix(security): harden password reset email code validation`, and `Fix redis ownership`. Keep commits focused and mention issue or PR numbers. Pull requests should include a clear summary, test results, migration notes if schema changes are included, and screenshots when UI, theme, or documentation images change.

## Security & Configuration Tips

Do not commit `.env`, secrets, generated keys, database dumps, or runtime files from `storage/`. Start from `.env.example` and document new environment variables. For security-sensitive flows, include positive and negative tests and note steps such as required `docker compose restart` after admin path changes.
