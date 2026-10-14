# AMS Backend

Laravel 12 / PHP 8.4 API, PostgreSQL 17, queued email and an internal Python face service. Companion React/TypeScript frontend: [ams-frontend](https://github.com/sahimsakir/ams-frontend), run separately without Docker.

## First run

Requirements: Docker with Compose v2 and Python 3. From this backend repository root, where `compose.yaml` lives:

```sh
python3 scripts/setup-env.py
docker compose config --quiet
docker compose build
docker compose run --rm api php artisan migrate --seed --force
docker compose run --rm api php artisan ams:auth-setup
docker compose up -d
```

Use OWNER_EMAIL/OWNER_PASSWORD from the generated `.env`. The API is at http://localhost:8000, health at /up, and the local email inbox at http://localhost:8025. PostgreSQL and face ports are internal. Keep `.env` private and preserve APP_KEY for existing encrypted templates.

## Update an existing installation

Update both repositories together, then reload the frontend and open a new camera session. In this backend folder:

```sh
git pull --ff-only
docker compose build api worker face
docker compose run --rm api php artisan migrate --force
docker compose run --rm api php artisan ams:auth-setup
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

Sessions remain bound to the user, profile, action, nonce, expiry and single use. Face mismatches, invalid service responses, unavailable verification, expired proofs and duplicate attendance fail closed. Exact previously used image bytes cannot be reused; this does not detect a newly photographed copy of a printed/screen image. Photos/templates remain private, and UTC ISO timestamps are displayed in Asia/Dhaka. A missing checkout becomes a missing exit after midnight in Asia/Dhaka; it cannot be checked out the next day and does not block a new check-in.

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

### Application forms and owner administration

Leave requests support emergency contact, covering person and an optional private JPG/PNG/PDF document (15 MB). Overtime supports first/last dates, total minutes (1–1440 per request), reason and a required approval document. All assigned reviewers still approve through the email workflow. Downloads require an authenticated applicant, assigned reviewer or owner.

Owners can edit and delete leave types and users. Deleted types are unavailable for new requests; historical records remain. Deleted users lose tokens, team memberships and face templates. Resolve pending applications/reviews before deleting a user. Self-deletion is blocked. CSV import is create-only, atomic, up to 500 rows/2 MB: required headers `name,email,password`; optional `role,active,designation,phone`. Roles: owner,manager,pm,team_lead,employee. Passwords: 12+ characters. Active: true/false or 1/0. Duplicate emails (including archived accounts) reject the import. Users enroll faces after login.

Update this release:
```bash
git pull --ff-only
docker compose build api worker
docker compose run --rm api php artisan migrate --force
docker compose run --rm api php artisan ams:auth-setup
docker compose up -d --force-recreate api worker
docker compose exec api php artisan optimize:clear
```
Keep your existing `.env`, `APP_KEY` and database volume. Also pull and restart the frontend.

### Monthly attendance reports and work slots

Only owners can view other users' attendance/photos; managers, PMs and team leads are limited to their own. `GET /api/attendance` defaults to the first of the current month through today (Asia/Dhaka), accepts `start_date`, `end_date`, `page`, and owner-only `user_id`. Ranges are limited to one year. Rows include office/home mode, approved working-day leave with type, assigned slot, elapsed minutes, minutes inside the slot and missing-exit status. Summary values cover the full filtered range, independent of pagination.

Rates use elapsed working days (Friday/Saturday excluded unless overridden by the calendar). Owner-wide totals use employee-days. Working-hour averages use completed shifts only; missing exits and today's unfinished shift are excluded. Actual hours are the intersection of check-in/out with the assigned slot. Early-arrival minutes are averaged across all check-ins; late-departure minutes across completed shifts. No lunch/break deduction is applied. Approved future leave appears as rows but does not inflate elapsed-day rates.

Owners set same-day slots via `PUT /api/work-slots/users/{id}` or `/teams/{id}` with nullable `slot_start` and `slot_end` (`HH:mm`), plus optional user `slot_team_id`. Precedence: individual override → selected configured member team → lowest-ID configured member team → 09:00–18:00. Clearing both times restores inheritance. Slots are snapshotted at check-in; existing records use 09:00–18:00 after migration. Pending exits become missing at local midnight on report evaluation; no scheduler or fabricated checkout is needed.

Run `docker compose build api worker`, `docker compose run --rm api php artisan migrate --force`, `docker compose up -d --force-recreate api worker`, and `docker compose exec api php artisan optimize:clear` after pulling. Then pull/restart the frontend.

### Spatie roles and permissions

Uses `spatie/laravel-permission` 6.x with `HasRoles`, `syncRoles`, `syncPermissions`, `hasPermissionTo`, and Laravel Gate integration. App Role/Permission extend Spatie models; the `api` guard is consistent across definitions and users. Spatie's role-permission table is configured as `role_permission` to preserve IDs/grants. `model_has_roles` and `model_has_permissions` are canonical account role/direct grant tables; `users.role_id` maintains the application's single-role constraint. Migration `000008` preserves existing grants and display labels. Legacy `user_permission` is retained only for migration rollback; runtime never reads it.

Initial roles: Owner, Management, Project Manager, Team Lead, Members. Owner → Roles and Owner → Permissions are separate menus. People → Role & permissions assigns one account role and additive direct permissions. Stable built-in keys and Owner access are protected; custom definitions support create/edit/delete. Reassign assigned roles before deletion. Only Owner manages access definitions/assignments. Managers of users/teams may read role choices without gaining access administration. Attendance/photos of other users remain Owner-only. Review decisions also require an actual assigned approval and never permit self-approval.

Permission model `name` stores the stable action key (Spatie standard), while `display_name` stores the UI label; `slug` mirrors the key for API compatibility. `/me` returns `effective_permissions`, `role_id`, `role_name`. Definition/grant changes use Spatie cache-aware methods and apply to existing cookies on subsequent requests.

For a new feature, create an action key such as `reports.export`, grant it through role/direct permissions, then use `$user->can('reports.export')`, `$user->hasPermissionTo('reports.export', 'api')`, or the existing `permission:reports.export` route middleware. Owner bypass is applied by the app wrapper/Gate; raw Spatie `hasPermissionTo` checks literal grants. Implement row-level data scope separately. Defining a permission does not itself implement a feature.

### Passport HttpOnly browser authentication

Uses `laravel/passport` 13.x personal access tokens with an eight-hour expiry. Login JSON contains user data only. The token is stored in an encrypted, HttpOnly, SameSite=Lax, host-only cookie named `ams_auth`, scoped to `/api`. Client bearer headers are rejected. React uses `credentials: include`; no local/session storage token is read or written. Public OAuth token routes are disabled because this app exposes only cookie login. Logout revokes the current Passport token and clears the cookie; password changes and disabling/deleting a user revoke their tokens.

API middleware encrypts cookies, starts a server-side session and checks Laravel CSRF tokens on all writes (including login/logout). GET `/api/csrf-cookie` initializes readable `XSRF-TOKEN`; clients send its URL-decoded value in `X-XSRF-TOKEN`. React initializes/retries once on 419. Only the CSRF token cookie is readable by JavaScript; the access token/session cookies are HttpOnly. Session rows store CSRF state; Passport validates authentication.

Use the frontend's same-origin `/api` proxy in development and serve frontend/API under one origin in production. Production must use HTTPS and `SESSION_SECURE_COOKIE=true`. Local HTTP development uses `SESSION_SECURE_COOKIE=false`. The session/auth cookie SameSite policy remains Lax. Keep APP_KEY unchanged; it encrypts auth cookies and face templates.

`ams:auth-setup` creates Passport signing keys in `storage/app/private/passport` and creates the personal access client if missing. It is idempotent and does not rotate existing keys. That directory is persisted by the private storage Docker volume, excluded from Git/build contexts, and must be backed up with your other private data. Run setup after migration and before the new API serves login. Do not use `passport:keys --force` during routine updates.

Upgrade from the previous release:
```bash
git pull --ff-only
docker compose build api worker
docker compose run --rm api php artisan migrate --force
docker compose run --rm api php artisan ams:auth-setup
docker compose up -d --force-recreate api worker
docker compose exec api php artisan optimize:clear
```
Existing users, roles, direct grants, enrollment and history are preserved. Previous custom bearer tokens are invalidated, so sign in again after pulling/restarting the frontend. No database reset or reseeding is needed.
