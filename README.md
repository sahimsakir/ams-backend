# AMS Backend

Laravel 12 / PHP 8.4 API, PostgreSQL 17, queued email and an internal Python face service. Companion React/TypeScript frontend: [ams-frontend](https://github.com/sahimsakir/ams-frontend), run separately without Docker.

## First run

Requirements: Docker with Compose v2 and Python 3. From this backend repository root, where `compose.yaml` lives:

```sh
python3 scripts/setup-env.py
docker compose config --quiet
docker compose build
docker compose run --rm api php artisan migrate --seed --force
docker compose up -d
```

Use OWNER_EMAIL/OWNER_PASSWORD from the generated `.env`. The API is at http://localhost:8000, health at /up, and the local email inbox at http://localhost:8025. PostgreSQL and face ports are internal. Keep `.env` private and preserve APP_KEY for existing encrypted templates.

## Update an existing installation

Update both repositories together, then reload the frontend and open a new camera session. In this backend folder:

```sh
git pull --ff-only
docker compose build api worker face
docker compose run --rm api php artisan migrate --force
docker compose up -d --force-recreate api worker face
docker compose exec api php artisan optimize:clear
```

Keep the existing `.env`, database and volumes. Do not regenerate APP_KEY, reset the database or reseed as part of an update. `docker compose down` stops services without deleting data; do not use `down -v` unless intentionally deleting all stored data.

## Face enrollment and attendance

Every role must have a verified face profile before using workspace features. First login automatically opens a mandatory enrollment dialog. Self-enrollment has three clear prompts: look forward, look left, look right. Enrolled owners can enroll other users in person from **People → Enroll face**, after confirming identity and consent. Replacing an existing profile requires an owner reset. Older version-1 templates must upgrade through the guided flow while matching their existing template, or be reset by an owner.

Attendance uses **one camera image matched against the enrolled face**:

1. Start the camera and select **Capture & check in/out**.
2. The server requires exactly one clearly visible face and compares its encoding with that account's enrolled templates.
3. A successful match records attendance immediately and returns the server timestamp. There is no head-turn, blinking, liveness or passive anti-spoof requirement for attendance.

Sessions remain bound to the user, profile, action, nonce, expiry and single use. Face mismatches, invalid service responses, unavailable verification, expired proofs and duplicate attendance fail closed. Exact previously used image bytes cannot be reused; this does not detect a newly photographed copy of a printed/screen image. Photos/templates remain private, and UTC ISO timestamps are displayed in Asia/Dhaka. Open overnight shifts remain available for checkout.

Enrollment stays at forward/left/right and still gates workspace access. The passive classifier and attendance head-turn checks were removed at the user's explicit request. `FACE_LIVE_MIN_SCORE` is obsolete. No ONNX/OpenCV dependency or anti-spoof weight download is needed.

## Practical limits

**Face-only attendance has no protection against presentation attacks.** A printed or screen-displayed image of the enrolled person can match and be accepted. Re-encoding or photographing an old image bypasses exact-byte replay detection. Login/profile requirements do not establish physical presence. Camera/model accuracy still needs testing on real devices; no guarantee that every genuine capture will match is made.

For phones/tablets, follow the frontend README's trusted HTTPS setup. Production requires a real PHP server, HTTPS, backups and a same-origin gateway allowing 12 MB request bodies and 120-second upstream timeouts. Never expose the internal face service.

## Policies and operations

All distinct assigned TL/manager/PM reviewers must approve applications. Teams may overlap, self-approval is forbidden, and missing reviewers fall back to other active owners. Pending leave reserves balance. Leave uses calendar-year limits, Friday/Saturday weekends and calendar overrides; split cross-year requests. Home office may be ongoing and revocable. Overtime requests contain a date and total minutes.

Mailpit handles local mail; configure SMTP environment values and recreate API/worker for real delivery. Schedule `docker compose exec -T api php artisan ams:prune --days=90` for your retention policy. It deletes expired challenges, temporary captures and old closed-shift photos, retaining timestamps and capture hashes. Templates remain until reset.

## Validation

```sh
docker compose exec api php artisan test
docker compose exec face python -m unittest discover -p 'test_*.py'
docker compose logs --tail=100 api worker face
```

40 Laravel tests / 245 assertions, seven Python motion tests and four browser tests pass. TypeScript/Vite build passes. Tests mock face inference or use synthetic geometry/fake cameras; they do not establish real-device accuracy. Docker image builds and PostgreSQL concurrency were not verified here. See [docs/VALIDATION.md](docs/VALIDATION.md) and [docs/API.md](docs/API.md).
