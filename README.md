<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Running with Docker

The app ships as a single container image (FrankenPHP, PHP 8.4) that runs the web app, the scheduler (mailbox polling, local file import, cleanup, queue) and, optionally, a built-in SMTP listener.

### Quick start

```bash
docker compose build
docker run --rm dmarc-monitor:latest php artisan key:generate --show   # prints a value for APP_KEY
cp .env.docker.example .env.docker                                     # set APP_KEY and APP_URL
docker compose up -d
```

The app is then available on <http://localhost:8080>. [compose.yaml](compose.yaml) runs one container with SQLite and a single volume; [compose.split.yaml](compose.split.yaml) runs web, scheduler and SMTP as separate services on MariaDB:

```bash
docker compose --env-file .env.docker -f compose.split.yaml up -d --build
```

Migrations run automatically when the container starts (set `RUN_MIGRATIONS=false` on additional replicas).

### Configuration

There is no `.env` file in the image: every setting is a container environment variable, and every variable from [.env.example](.env.example) works. The compose files pass `.env.docker` to the container as its environment. [.env.docker.example](.env.docker.example) lists the useful ones with production defaults (`APP_KEY` is required).

Variables specific to the container:

| Variable | Default | Purpose |
| --- | --- | --- |
| `RUN_MIGRATIONS` | `true` | Run `migrate --force` on start (web and all-in-one roles). |
| `TRUSTED_PROXIES` | unset | Comma-separated proxy addresses, or `*`, whose `X-Forwarded-*` headers are trusted. Set it when a reverse proxy terminates HTTPS, otherwise generated links use `http://`. |
| `DMARC_SMTP_ENABLED` | `false` | Start the SMTP listener (see below). |

Logs go to the container's standard error (`LOG_CHANNEL=stderr`), so use `docker compose logs`.

### Roles

The container's command selects what it runs:

| Command | Runs |
| --- | --- |
| `all` (default) | Web app + scheduler, plus the SMTP listener when `DMARC_SMTP_ENABLED=true`. |
| `web` | Web app only (port 8080). |
| `scheduler` | `php artisan schedule:work` only. |
| `smtp` | The SMTP listener only (port 2525); exits immediately unless `DMARC_SMTP_ENABLED=true`. |
| anything else | Run as given, e.g. `docker compose exec app php artisan tinker`. |

Use a database other than SQLite (MySQL/MariaDB or PostgreSQL) when running several containers, as in `compose.split.yaml`.

### Data

Persist `/app/storage`: it holds the SQLite database (when used), stored report files, the GeoIP databases and the local import folder. To feed the local `.eml`/`.msg` import from a host folder, mount it at `/app/storage/app/dmarc-eml/inbox` (or set `DMARC_EML_IMPORT_PATH`). The container runs as uid 1000, so a bind-mounted folder must be writable by that user.

### SMTP listener

Set `DMARC_SMTP_ENABLED=true` to accept report mail over SMTP from a relay or forwarder you control. Only clients listed in `DMARC_SMTP_ALLOWED_IPS` (IPs, CIDR ranges, or `spf:<domain>` such as `spf:spf.protection.outlook.com`) may connect, and while it is empty nobody outside the container can, so it must be set. Inside Docker the sender's address is usually the Docker network gateway or your proxy. Publish port 2525 only to the network the relay is on. Optional STARTTLS is enabled with `DMARC_SMTP_TLS_CERT` and `DMARC_SMTP_TLS_KEY` (mount the certificate into the container). See the in-app Help > Mail Ingestion page for details.

### Publishing the image

```bash
docker build -t ghcr.io/<owner>/dmarc-monitor:<version> .
docker push ghcr.io/<owner>/dmarc-monitor:<version>
```

Then point the `image:` line of the compose files at that name.

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
