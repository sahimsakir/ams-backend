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

Attendance now uses **one photo followed by one brief random head turn**:

1. Start the camera, look forward and select **Capture & check in/out**.
2. After matching the enrolled face, the browser displays **Turn slightly LEFT/RIGHT** and counts down.
3. Turn about 20–25 degrees in that direction and hold briefly. Four fresh frames are captured automatically. There is no blinking requirement and no extra click on the successful path.
4. Only after face identity and the requested movement pass does the server record attendance and return its date/time. If the movement did not pass, reposition and select **Retry head-turn check**, or restart the camera.

A photo match alone does not authorize attendance. The session is bound to its user, enrolled template, action, nonce, expiry and single use. The server checks one face per frame, a change in landmark geometry from the initial photo, identity continuity and repeated frame hashes. Exact attendance-photo reuse remains blocked even after old photos are pruned. Photos/templates stay private, and API timestamps use UTC ISO format displayed in Asia/Dhaka. Open overnight shifts remain available for checkout.

The passive RGB classifier was removed from attendance because it falsely rejected the user's real webcam capture with score 0.0012 despite a matching face. `FACE_LIVE_MIN_SCORE` is obsolete and has no effect; an old value can be removed from `.env`. The old passive-only proof is never accepted as a new attendance authorization. No ONNX/OpenCV dependency or anti-spoof weight download is required by this version.

## Practical limits

Active head-pose verification helps reject an unchanged photograph; it is **not certified presentation-attack detection** and cannot guarantee rejection of perspective-distorted photographs, recordings, deepfakes or virtual cameras. API uploads cannot attest the physical source of camera frames. A first enrollment establishes the account's template; it does not independently verify a government identity or prevent account sharing. Physical camera compatibility, pose thresholds, accessibility and attack rejection must be tested with your employees and devices. The real webcam after this update has not been verified in this workspace. There is no photo-only bypass.

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

41 Laravel tests / 267 assertions, seven Python motion tests and four browser tests pass. TypeScript/Vite build passes. Tests mock face inference or use synthetic geometry/fake cameras; they do not establish real-device accuracy. Docker image builds and PostgreSQL concurrency were not verified here. See [docs/VALIDATION.md](docs/VALIDATION.md) and [docs/API.md](docs/API.md).
