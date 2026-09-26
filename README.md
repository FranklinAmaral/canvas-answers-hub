# GraderAI

GraderAI is an internal Afya application for AI-assisted grading of Canvas LMS
Classic Quiz essay questions. It provides an administrative panel, LTI entry
points, background processing and publication of reviewed grades to Canvas.

## Requirements

- PHP 8.3 or newer with the extensions installed by `docker/php/Dockerfile`
- Composer 2
- Node.js 22
- MySQL 8.4

## Required configuration

Copy `.env.example` to `.env`, generate `APP_KEY` and configure at least:

```dotenv
APP_NAME=GraderAI
APP_URL=https://admin.graderai.example.com
GRADERAI_DOMAIN=lti.graderai.example.com
GRADERAI_URL=https://lti.graderai.example.com
GRADERAI_DB_VOLUME=graderai-mysql-data

DB_CONNECTION=mysql
DB_HOST=graderai-mysql
DB_PORT=3306
DB_DATABASE=graderai
DB_USERNAME=graderai
DB_PASSWORD=
DB_ROOT_PASSWORD=
```

`APP_URL` is the administrative application URL. `GRADERAI_DOMAIN` is the
isolated host accepted by the LTI routes, and `GRADERAI_URL` is its public base
URL used when generating Canvas LTI configuration. Define real database
passwords locally; the repository contains no default credentials.
An existing deployment can point `GRADERAI_DB_VOLUME` to its current Docker
volume during the infrastructure-name transition, avoiding a data copy.

## Installation

```bash
composer install
php artisan key:generate
npm ci
npm run build
php artisan migrate
```

No administrative user is created by `DatabaseSeeder`. Provision users through
an explicit operational process.

## Docker

After configuring `.env`, start the application, nginx, MySQL, queue worker and
scheduler with:

```bash
docker compose up -d --build
```

The default HTTP port is `8088` and the forwarded MySQL port is `3307`.

## Validation

```bash
composer validate
npm ci
npm run build
php artisan optimize:clear
php artisan route:list
php artisan test
```

## Deployment

Run `./deploy.sh` from the repository. When the checkout is elsewhere, set
`GRADERAI_PROJECT_DIR` to its absolute path. The script rebuilds frontend assets,
installs optimized Composer dependencies, runs migrations and caches, then
restarts the queue worker and scheduler.
