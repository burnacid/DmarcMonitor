# DMARC Monitor

Collects and analyses DMARC reports for your domains. Aggregate (RUA) and forensic (RUF) reports are fetched from IMAP or Microsoft 365 mailboxes, imported from local `.eml`/`.msg` files, or received over a built-in SMTP listener. The app shows them per organisation and domain, enriches source IPs with reverse DNS and GeoIP/ASN data, checks your DMARC/SPF/DKIM DNS records, and sends alerts. Built with Laravel, Livewire and Volt.

## Running with Docker

The app ships as a single container image, [`burnacid/dmarc-monitor`](https://hub.docker.com/r/burnacid/dmarc-monitor) on Docker Hub (FrankenPHP, PHP 8.4, `linux/amd64`), that runs the web app, the scheduler (mailbox polling, local file import, cleanup, queue) and, optionally, the SMTP listener. Tags: `latest` and version tags such as `1.0.0`.

### Quick start

```bash
docker run --rm burnacid/dmarc-monitor:latest php artisan key:generate --show   # prints a value for APP_KEY
cp .env.docker.example .env.docker                                              # set APP_KEY and APP_URL
docker compose pull
docker compose up -d
```

The app is then available on <http://localhost:8080>. [compose.yaml](compose.yaml) runs one container with SQLite and a single volume; [compose.split.yaml](compose.split.yaml) runs web, scheduler and SMTP as separate services on MariaDB:

```bash
docker compose --env-file .env.docker -f compose.split.yaml up -d
```

To build the image from this repository instead of pulling it, add `--build` to `docker compose up`.

Migrations run automatically when the container starts (set `RUN_MIGRATIONS=false` on additional replicas).

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

Logs go to the container's standard error, so use `docker compose logs`.

### SMTP listener

Set `DMARC_SMTP_ENABLED=true` to accept report mail over SMTP from a relay or forwarder you control. Only clients listed in `DMARC_SMTP_ALLOWED_IPS` (IPs, CIDR ranges, or `spf:<domain>` such as `spf:spf.protection.outlook.com`) may connect, and while it is empty nobody outside the container can, so it must be set. Inside Docker the sender's address is usually the Docker network gateway or your proxy. Publish port 2525 only to the network the relay is on. Optional STARTTLS is enabled with `DMARC_SMTP_TLS_CERT` and `DMARC_SMTP_TLS_KEY` (mount the certificate into the container). See the in-app Help > Mail Ingestion page for details.

### Publishing the image

```bash
docker buildx build --platform linux/amd64 \
  -t burnacid/dmarc-monitor:<version> -t burnacid/dmarc-monitor:latest --push .
```

## Environment variables

There is no `.env` file in the image: every setting is a container environment variable. The compose files pass `.env.docker` (created from [.env.docker.example](.env.docker.example)) to the container as its environment. Any other Laravel variable read by the files in [config/](config) works as well. "Image default" is what the image sets when you don't.

### Required

| Variable | Default | Description |
| --- | --- | --- |
| `APP_KEY` | none | Encryption key; the container refuses to start without it. Generate with `php artisan key:generate --show`. |
| `APP_URL` | `http://localhost` | Public URL of the app, used in links and emails. |

### Application

| Variable | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `Laravel` | Application name (page titles, email sender name). |
| `APP_ENV` | `production` (image) | Environment name. |
| `APP_DEBUG` | `false` (image) | Show detailed errors. Keep `false` in production. |
| `APP_LOCALE` | `en` | Interface language. |
| `APP_FALLBACK_LOCALE` | `en` | Fallback language. |
| `APP_PREVIOUS_KEYS` | empty | Comma-separated old `APP_KEY` values, for key rotation. |
| `APP_MAINTENANCE_DRIVER` | `file` | Maintenance mode driver (`file` or `cache`). |
| `BCRYPT_ROUNDS` | `12` | Password hashing cost. |

### Container

| Variable | Default | Description |
| --- | --- | --- |
| `RUN_MIGRATIONS` | `true` | Run `migrate --force` on start (web and all-in-one roles). Set `false` on additional replicas. |
| `TRUSTED_PROXIES` | unset | Comma-separated proxy addresses, or `*`, whose `X-Forwarded-*` headers are trusted. Set it when a reverse proxy terminates HTTPS, otherwise generated links use `http://`. |
| `DMARC_SMTP_ENABLED` | `false` | Start the SMTP listener. |
| `DMARC_SMTP_PUBLISHED_PORT` | `2525` | Host port for the `smtp` service in `compose.split.yaml` only (a compose variable, not read by the app). |

### Database

| Variable | Default | Description |
| --- | --- | --- |
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql`, `mariadb` or `pgsql`. |
| `DB_DATABASE` | `/app/storage/database/database.sqlite` (image) | SQLite file path, or the database name for the other drivers (`laravel` if unset). |
| `DB_HOST` | `127.0.0.1` | Database host. |
| `DB_PORT` | `3306` / `5432` | Database port. |
| `DB_USERNAME` | `root` | Database user. |
| `DB_PASSWORD` | empty | Database password. |
| `DB_URL` | unset | Full connection URL, overriding the individual `DB_*` settings. |
| `DB_SOCKET` | empty | Unix socket (MySQL/MariaDB). |
| `DB_SSLMODE` | `prefer` | PostgreSQL SSL mode. |
| `MYSQL_ATTR_SSL_CA` | unset | CA file for TLS connections to MySQL/MariaDB. |

### Session, cache and queue

| Variable | Default | Description |
| --- | --- | --- |
| `SESSION_DRIVER` | `database` | Session store. |
| `SESSION_LIFETIME` | `10080` | Session lifetime in minutes. |
| `SESSION_ENCRYPT` | `false` | Encrypt session data. |
| `SESSION_SECURE_COOKIE` | unset | Set `true` to send the session cookie over HTTPS only (recommended behind HTTPS). |
| `SESSION_DOMAIN` | unset | Cookie domain. |
| `CACHE_STORE` | `database` | Cache store. |
| `CACHE_PREFIX` | app name | Cache key prefix. |
| `QUEUE_CONNECTION` | `database` | Queue backend. The scheduler drains the queue every five minutes; no separate queue worker is needed. |

### Redis (only when used for cache, session or queue)

| Variable | Default | Description |
| --- | --- | --- |
| `REDIS_CLIENT` | `phpredis` | Redis client; `phpredis` is the one included in the image. |
| `REDIS_URL` | unset | Full connection URL. |
| `REDIS_HOST` | `127.0.0.1` | Redis host. |
| `REDIS_PORT` | `6379` | Redis port. |
| `REDIS_USERNAME` | unset | Redis user. |
| `REDIS_PASSWORD` | unset | Redis password. |
| `REDIS_DB` | `0` | Database number. |
| `REDIS_CACHE_DB` | `1` | Database number for the cache. |

### Logging

| Variable | Default | Description |
| --- | --- | --- |
| `LOG_CHANNEL` | `stderr` (image) | Log channel; `stderr` shows up in `docker logs`. |
| `LOG_LEVEL` | `debug` | Minimum level (`debug`, `info`, `warning`, `error`, ...). |
| `LOG_STACK` | `single` | Channels used by the `stack` channel. |
| `LOG_DAILY_DAYS` | `14` | Days of logs kept by the `daily` channel. |

### Outgoing mail (alert emails)

| Variable | Default | Description |
| --- | --- | --- |
| `MAIL_MAILER` | `log` | `log`, `smtp`, `sendmail`, ... or `microsoft365` to send via Microsoft Graph using the account set under Settings > Microsoft 365 Sending Account. |
| `MAIL_HOST` | `127.0.0.1` | SMTP server (for `smtp`). |
| `MAIL_PORT` | `2525` | SMTP port. |
| `MAIL_USERNAME` | unset | SMTP user. |
| `MAIL_PASSWORD` | unset | SMTP password. |
| `MAIL_SCHEME` | unset | `smtp` or `smtps` (implicit TLS). |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | Sender address. |
| `MAIL_FROM_NAME` | app name | Sender name. |
| `MAIL_EHLO_DOMAIN` | host of `APP_URL` | Domain announced to the SMTP server. |

### Report data

| Variable | Default | Description |
| --- | --- | --- |
| `DATA_RETENTION_DAYS` | `400` | Days of report data, resolved alert events, trashed domains and audit logs to keep. |
| `DMARC_EML_IMPORT_PATH` | `/app/storage/app/dmarc-eml` | Base folder of the local `.eml`/`.msg` import: files are read from `inbox/` and moved to `processed/` or `failed/` inside it. |
| `MAXMIND_LICENSE_KEY` | unset | MaxMind licence key; enables the weekly GeoLite2 download. Without it reverse DNS still works. |
| `MAXMIND_ASN_DB_PATH` | `/app/storage/app/geoip/GeoLite2-ASN.mmdb` | Path of the GeoLite2 ASN database. |
| `MAXMIND_COUNTRY_DB_PATH` | `/app/storage/app/geoip/GeoLite2-Country.mmdb` | Path of the GeoLite2 Country database. |
| `GEOIP_CACHE_DAYS` | `30` | Days an IP's enrichment result is cached. |

### SMTP listener

| Variable | Default | Description |
| --- | --- | --- |
| `DMARC_SMTP_ENABLED` | `false` | Start the listener (see Container above). |
| `DMARC_SMTP_HOST` | `0.0.0.0` (image) | Address to listen on. |
| `DMARC_SMTP_PORT` | `2525` | Port to listen on. |
| `DMARC_SMTP_ALLOWED_IPS` | empty | Clients allowed to connect: IPs, CIDR ranges or `spf:<domain>`, comma-separated. Empty means only loopback, i.e. nobody from outside the container. |
| `DMARC_SMTP_MAX_MESSAGE_BYTES` | `26214400` | Largest message accepted (25 MB). |
| `DMARC_SMTP_IDLE_TIMEOUT` | `60` | Seconds before an idle connection is closed. |
| `DMARC_SMTP_MAX_CONNECTIONS` | `20` | Maximum simultaneous connections. |
| `DMARC_SMTP_TLS_CERT` | unset | PEM certificate file; setting it enables STARTTLS. |
| `DMARC_SMTP_TLS_KEY` | unset | Private key file, if not in the certificate file. |
| `DMARC_SMTP_TLS_PASSPHRASE` | unset | Passphrase of the private key. |
| `DMARC_SMTP_TLS_REQUIRED` | `false` | Refuse mail that is not sent over TLS. |
