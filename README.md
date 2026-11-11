# GraderAI

Internal Afya Laravel application for AI-assisted grading of Canvas LMS Classic
Quiz essay questions, with administration, LTI, background grading and publication
of reviewed grades.

## Requirements

- PHP **8.4+** for CLI and PHP-FPM, required by the locked Symfony packages.
  Composer resolves against PHP 8.4.0 to preserve this minimum.
- Extensions: ctype, dom, fileinfo, filter, hash, iconv, intl, mbstring, openssl,
  pcre, PDO, pdo_mysql, session, tokenizer, xml, xmlreader, xmlwriter and zip.
  Preserve bcmath, exif and GD with JPEG/FreeType from the previous runtime.
  Enable **pcntl** for Linux queue timeouts, pdo_sqlite/sqlite3 for tests.
  curl and OPcache are recommended.
- Composer 2 supporting `--minimal-changes`, Node.js 22.12+ (22 LTS recommended),
  npm, MySQL 8.4, Linux process supervision, cron and HTTPS server with PHP-FPM.
- Redis is optional: defaults use database queues, sessions and cache.

The environment team provisions these services directly, without containers.
See [security tracking](docs/security/FOLLOW-UP.md).

## Configuration and installation

Copy `.env.example` to `.env`, restrict access to the deployment/service account
and configure it before migrations. Never commit `.env` or keys.

- Keep `APP_ENV=production`, `APP_DEBUG=false`; set `APP_URL` to the HTTPS admin URL.
- Set `GRADERAI_DOMAIN` to the isolated LTI hostname, `GRADERAI_URL` to its HTTPS
  base URL. Configure DNS, certificates and both hosts on the web server.
- Set DB host/port/database/username/password for the provisioned MySQL database.
  Use a dedicated account, never root. No database is created by this repository.
- Keep `QUEUE_CONNECTION=database` and `DB_QUEUE_RETRY_AFTER=7260`, exceeding the
  longest job timeout (7200 seconds), identically on all workers. With Redis,
  configure `REDIS_QUEUE_RETRY_AFTER` likewise.
- Keep secure cookies and `SESSION_SAME_SITE=none` for embedded HTTPS LTI.
- Configure mail delivery for password recovery; the default `log` sends no mail.
  Use `LOG_LEVEL=info` in production and configure log rotation/retention.
- Supply LTI signing key/id in protected external configuration when needed;
  preserve PEM newlines. Canvas credentials come from the selected user's
  `CanvasEnvironment` records. Protect database backups and preserve `APP_KEY`.
- Configure AI providers in the product. Ensure connectivity to Canvas and AI
  services. Replace legacy container-only hostnames in existing database settings
  through the authorized administration process. Timezone comes from organization/
  environment context; timestamps use `Y-m-d H:i:s`.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
php tools/check-nette-licenses.php
php artisan key:generate
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Generate `APP_KEY` only for a new installation. Take an approved backup before
updates/migrations. Migrations include jobs, failed jobs, sessions and cache.
`DatabaseSeeder` creates no administrator: provision users through the explicit
operational process. Do not reset or seed existing databases during deployment.

## Web server and permissions

Serve **only `public/`** for both HTTPS hosts. Route missing paths to
`/index.php?$query_string`, forward only the entry point to the local PHP-FPM
socket/service, deny hidden files except ACME challenges. Do not expose `.env`,
repository root, vendor or storage. Set actual TLS/proxy HTTPS metadata and trust
only managed proxies. Preserve the previous 50 MiB request ceiling when needed;
align PHP `upload_max_filesize`/`post_max_size` with authorized upload limits.

The unprivileged application account needs write access to `storage/` and
`bootstrap/cache/`; keep other code read-only for the web process. Do not use
world-writable permissions. The environment team owns PHP-FPM, firewall/binds,
database isolation/backups, OS patches, SELinux policies/labels, TLS and logs.
Probe `/up` over HTTPS for liveness; monitor DB connectivity, failed jobs and cron
separately. Do not expose MySQL or PHP-FPM publicly.

## Queue and scheduler

Run a supervised worker as the application account from the project directory:

```bash
php artisan queue:work --sleep=3 --tries=1 --timeout=7200
```

Configure automatic restart, the same environment/PHP, and a stop grace period
exceeding 7200 seconds. Jobs define one attempt and 3600/7200 second timeouts.
Enable pcntl on Linux. Run one scheduler instance using cron (replace paths):

```cron
* * * * * cd /srv/graderai && /usr/bin/php artisan schedule:run >> /var/log/graderai-scheduler.log 2>&1
```

The pipeline dispatcher runs every two minutes with overlap prevention. The
environment team owns cron/log permissions and monitoring. Do not also run
`schedule:work` with this cron entry.

## Updates and development

After reviewing/checking out the desired revision, backing up the database and
configuring the host, run `bash deploy.sh` (or set `GRADERAI_PROJECT_DIR`). It
builds assets, installs production dependencies, migrates, refreshes caches and
signals workers to restart gracefully. It performs no Git pull and configures no
system services. Supervision must restart exited workers. Arrange a maintenance
window when migrations require it. This script is not executed as part of checks.

For development, `composer install` includes dev dependencies. Use isolated local
DB, URLs and cookie settings, `APP_ENV=local`, then `composer dev` on compatible
Unix systems. Development servers/Vite must not serve production interfaces.

## Validation

Distributions must retain [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md),
`docs/licenses/NETTE-LICENSE.md` and the original Composer package notices.
This applies to source archives and documentation/materials accompanying binary
or packaged releases. Run `php tools/check-nette-licenses.php` from the complete
distribution directory after Composer installation. CI and deploy check the
verified Nette versions and notice integrity; do not strip notices afterwards.
The adoption applies only to these two dependencies and preserves the project's
proprietary license. Security/Compliance validation remains pending.

Use isolated testing configuration with SQLite in memory and no production
credentials. No static analyzer is configured. Pint checks existing formatting;
avoid formatting unrelated application files.

```bash
composer validate --strict
composer audit --locked
composer install --prefer-dist --no-interaction
composer check-platform-reqs
php tools/check-nette-licenses.php
npm ci
npm audit
npm run build
vendor/bin/pint --test
php artisan test
php artisan view:cache
php artisan route:list --except-vendor
php artisan route:cache
```

GitHub Actions runs these checks on PHP 8.4/8.5 without deployment secrets or an
external database. No Git remote is configured here; for another Git platform,
port the same commands. Workflow files do not activate branch protections:
administrators must require checks and review controls in the tracking document.
