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

Read OWNER_EMAIL and OWNER_PASSWORD from the generated `.env`. Set up people and teams; each user completes guided face enrollment, or an enrolled owner enrolls them from People through the frontend at http://localhost:5173. API: http://localhost:8000; health: /up; local test inbox: http://localhost:8025. `.env` must not be committed. Preserve APP_KEY when migrating an existing database.

Compose lives at the root of this backend repository. Face service is bundled under `face-service/`, so no sibling source folders are needed. PostgreSQL and face service ports are internal. The frontend runs separately with npm.

## Verification

```sh
docker compose exec api php artisan test
docker compose logs --tail=100 api worker face
```

39 automated tests (240 assertions) passed with SQLite; Docker/PostgreSQL runtime and actual face accuracy have not been verified here. See docs/VALIDATION.md. Mailpit captures local email; configure MAIL_HOST/PORT/USERNAME/PASSWORD and FROM settings for delivery and recreate API/worker.

## Policy

All distinct assigned TL/manager/PM reviewers must approve. Team memberships can overlap. No self-approval. Missing team reviewers fall back to other active owners. Pending leave reserves balance. Full-day leave uses a calendar-year allowance, Friday/Saturday weekends and calendar overrides. Cross-year leave must be split. Home office may have no end date and remains revocable; attendance always requires real check-in/out. Overtime requests contain one date and total minutes.

## Deployment limitations

This is a local development setup using Laravel's development server. Production needs HTTPS, a production PHP server, backups and model validation. Attendance uses face matching plus a passive RGB anti-spoof classifier. It rejects suspected spoof captures but cannot guarantee detection of every printed photo, screen replay or virtual camera. Enroll only with employee consent. Templates are encrypted; attendance photos are private. Set your retention policy using `php artisan ams:prune --days=90` inside the API container.

Run `docker compose down` to stop without deleting volumes. Do not use `down -v` unless deleting all stored data intentionally. API details: docs/API.md.

## Update an existing installation

Pull and update both repositories together: the single-photo API has been replaced.

```sh
git pull --ff-only
docker compose build api worker face
docker compose run --rm api php artisan migrate --force
docker compose up -d --force-recreate api worker face
```

Keep your existing `.env`, `APP_KEY`, database and volumes. Do not rerun initial environment generation or seed/reset production data for this update. Every role must enroll before accessing the workspace. Owners can also enroll other users from People after completing their own enrollment. Existing profiles need the guided upgrade; they must match the previous template, unless an owner resets it. A verified enrollment cannot be silently overwritten.

Enrollment uses three checks: look forward, look left, and look right. Check-in and checkout each capture one camera photo, which must pass enrolled-face matching and the passive anti-spoof score threshold. No head-turn or eye-closing challenge is required for attendance. Simple visual examples and capture instructions explain each step. Enrollment opens automatically after login and cannot be dismissed; only enrollment and sign-out are available until it passes. The API also blocks other workspace features for unenrolled accounts. Timestamps come from the server and include full dates and seconds in the frontend. Open overnight shifts remain available for checkout.

For mobile access, use the frontend README's trusted HTTPS setup. The API can stay bound to `127.0.0.1:8000`; Vite proxies `/api` to it, including camera bursts. A deployed reverse proxy must allow at least 12 MB request bodies and a 120-second upstream timeout. Only expose the HTTPS frontend/API gateway, never the internal face service.

Schedule `docker compose exec -T api php artisan ams:prune --days=90` hourly (or with your organization's retention schedule). It deletes expired challenge records, temporary captures and their encrypted intermediate templates, plus attendance photos past the chosen retention period. Enrolled templates remain until an owner resets them. Protect backups and preserve the encryption key.

Enrollment uses dlib/face_recognition with geometric head-pose checks. Attendance adds the anti-spoof-mn3 classifier from Open Model Zoo, executed with ONNX Runtime on CPU. Accuracy and accessibility must be evaluated using your employees, cameras, lighting and required threat model. There is no measured accuracy claim or certified PAD model in this release. An account's first enrollment establishes its template; it does not verify a government identity or prevent account sharing. People unable to perform the enrollment head prompts need an agreed supervised process; there is no hidden attendance bypass.

## Single-capture attendance anti-spoof check

Rebuild **face, api and worker**, apply the new capture-hash migration, then recreate the services (use the update commands above). The face image downloads the pinned 12.27 MB `anti-spoof-mn3` ONNX model during its Docker build. The download is size/SHA-384 checked against the official Open Model Zoo manifest; a download failure or mismatched weight stops the build. At service startup, missing/changed model weights stop the face service. The weights are not committed to GitHub. Their upstream license is included in `face-service/ANTI-SPOOF-LICENSE.txt`.

A user starts the camera and selects **Capture & check in/out**. Laravel requires a fresh session, enrolled face match, and model real-face score at or above `FACE_LIVE_MIN_SCORE` (default **0.95**). Missing, invalid or uncertain classification produces no attendance. Configuration below 0.90 is rejected, rather than disabling anti-spoofing. These values are starting settings, not validated operating thresholds for your cameras. Calibrate with both real faces and printed/screen-displayed attacks before use.

An exact previously used attendance image cannot be submitted again, even after its photo is pruned; private SHA-256 capture hashes are retained with timestamps. This is exact-byte replay protection, not a guarantee against a modified image. Session IDs/nonces do not attest that bytes physically originated from a real camera. The browser has no file upload option, but an attacker may call the API directly.

This single-RGB-frame model is **best-effort passive presentation-attack detection**, not certified liveness. Printed photographs, screens and unseen attack types may be misclassified, and real users may be rejected. If guaranteed photo rejection is a business requirement, a normal webcam photo does not meet it; require an independently evaluated capture/PAD system or stronger verification. There is no weaker fallback that skips the classifier.

Model/preprocessing references: [Open Model Zoo model](https://github.com/openvinotoolkit/open_model_zoo/tree/master/models/public/anti-spoof-mn3), [upstream demo](https://github.com/kprokofi/light-weight-face-anti-spoofing). This implementation uses the original model's RGB input, 128×128 face crop and published mean/scale, and treats output class 0 as real.
