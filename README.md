# AMS Backend

Laravel 12 / PHP 8.4 API, PostgreSQL 17, queued email and internal Python face verification. Companion React client: `ams-frontend` repository.

## Run

Requirements: Docker with Compose v2 and Python 3. From this repository root:

```sh
python3 scripts/setup-env.py
docker compose config --quiet
docker compose build
docker compose run --rm api php artisan migrate --seed --force
docker compose up -d
```

Read OWNER_EMAIL and OWNER_PASSWORD from the generated `.env`. Set up people, teams and supervised face enrollment through the frontend at http://localhost:5173. API: http://localhost:8000; health: /up; local test inbox: http://localhost:8025. `.env` must not be committed. Preserve APP_KEY when migrating an existing database.

Compose lives at the root of this backend repository. Face service is bundled under `face-service/`, so no sibling source folders are needed. PostgreSQL and face service ports are internal. The frontend runs separately with npm.

## Verification

```sh
docker compose exec api php artisan test
docker compose logs --tail=100 api worker face
```

21 automated tests passed in the original implementation with SQLite; Docker/PostgreSQL runtime and actual face accuracy have not been verified here. See docs/VALIDATION.md. Mailpit captures local email; configure MAIL_HOST/PORT/USERNAME/PASSWORD and FROM settings for delivery and recreate API/worker.

## Policy

All distinct assigned TL/manager/PM reviewers must approve. Team memberships can overlap. No self-approval. Missing team reviewers fall back to other active owners. Pending leave reserves balance. Full-day leave uses a calendar-year allowance, Friday/Saturday weekends and calendar overrides. Cross-year leave must be split. Home office may have no end date and remains revocable; attendance always requires real check-in/out. Overtime requests contain one date and total minutes.

## Deployment limitations

This is a local development setup using Laravel's development server. Production needs HTTPS, a production PHP server, backups and model validation. Face matching does not provide liveness or anti-spoofing. Enroll only with employee consent. Templates are encrypted; attendance photos are private. Set your retention policy using `php artisan ams:prune --days=90` inside the API container.

Run `docker compose down` to stop without deleting volumes. Do not use `down -v` unless deleting all stored data intentionally. API details: docs/API.md.
