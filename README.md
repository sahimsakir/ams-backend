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

Attendance uses **one tap, four automatic camera frames, face matching and passive liveness**:

1. Start the camera and select **Capture & check in/out**.
2. The server requires exactly one clearly visible face and compares its encoding with that account's enrolled templates.
3. Successful face matching and liveness checks record attendance and return the server timestamp. Attendance requires MiniVision passive liveness on four automatic frames as well as face matching; there are no head-turn or blinking prompts.

Sessions remain bound to the user, profile, action, nonce, expiry and single use. Face mismatches, invalid service responses, unavailable verification, expired proofs and duplicate attendance fail closed. Exact previously used image bytes cannot be reused; this does not detect a newly photographed copy of a printed/screen image. Photos/templates remain private, and UTC ISO timestamps are displayed in Asia/Dhaka. A missing checkout becomes a missing exit after midnight in Asia/Dhaka; it cannot be checked out the next day and does not block a new check-in.

Enrollment stays at forward/left/right and still gates workspace access. Attendance uses MiniVision MiniFASNetV2 + MiniFASNetV1SE CPU models. Docker downloads pinned SHA-256-checked weights during build. `FACE_LIVE_MIN_SCORE` defaults to 0.8; it must be between 0.5 and 1. Head-turn checks remain limited to enrollment.

## Practical limits

**Passive liveness is not certified protection.** It can reject genuine users or accept unseen attacks. Test real people and printed/screen/video attacks on your actual cameras and lighting before relying on it. The previous classifier's false rejections are not assumed solved by this integration. Camera injection/replay is not cryptographically prevented by a browser frame sequence.

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

Attendance visibility is permission-scoped: `attendance.view` for self, `attendance.view_team` for assigned teams, and `attendance.view_all` for everyone. Team Leads receive team visibility by default. Photos require separate self/team/all photo permissions. `GET /api/attendance` defaults to the first of the current month through today (Asia/Dhaka), accepts `start_date`, `end_date`, `page`, and scope-checked `user_id`. Ranges are limited to one year. Rows include office/home mode, approved working-day leave with type, assigned slot, elapsed minutes, minutes inside the slot and missing-exit status. Summary values cover the full filtered range, independent of pagination.

Rates use elapsed working days (Friday/Saturday excluded unless overridden by the calendar). Owner-wide totals use employee-days. Working-hour averages use completed shifts only; missing exits and today's unfinished shift are excluded. Actual hours are the intersection of check-in/out with the assigned slot. Early-arrival minutes are averaged across all check-ins; late-departure minutes across completed shifts. No lunch/break deduction is applied. Approved future leave appears as rows but does not inflate elapsed-day rates.

Owners set same-day slots via `PUT /api/work-slots/users/{id}` or `/teams/{id}` with nullable `slot_start` and `slot_end` (`HH:mm`), plus optional user `slot_team_id`. Precedence: individual override → selected configured member team → lowest-ID configured member team → 09:00–18:00. Clearing both times restores inheritance. Slots are snapshotted at check-in; existing records use 09:00–18:00 after migration. Pending exits become missing at local midnight on report evaluation; no scheduler or fabricated checkout is needed.

Run `docker compose build api worker`, `docker compose run --rm api php artisan migrate --force`, `docker compose up -d --force-recreate api worker`, and `docker compose exec api php artisan optimize:clear` after pulling. Then pull/restart the frontend.

### Spatie roles and permissions

Uses `spatie/laravel-permission` 6.x with `HasRoles`, `syncRoles`, `syncPermissions`, `hasPermissionTo`, and Laravel Gate integration. App Role/Permission extend Spatie models; the `api` guard is consistent across definitions and users. Spatie's role-permission table is configured as `role_permission` to preserve IDs/grants. `model_has_roles` and `model_has_permissions` are canonical account role/direct grant tables; `users.role_id` maintains the application's single-role constraint. Migration `000008` preserves existing grants and display labels. Legacy `user_permission` is retained only for migration rollback; runtime never reads it.

Initial roles: Owner, Management, Project Manager, Team Lead, Members. Owner → Roles and Owner → Permissions are separate menus. People → Role & permissions assigns one account role and additive direct permissions. Stable built-in keys and Owner access are protected; custom definitions support create/edit/delete. Reassign assigned roles before deletion. Access definitions and assignments require individual role/permission action grants; delegated administrators cannot grant privileges they do not hold or modify Owner access. Managers of users/teams may read role choices without gaining access administration. Team Lead attendance and request visibility covers the union of assigned teams; unrelated users are excluded. Review decisions also require an actual assigned approval and never permit self-approval.

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

### Table filters and team home office

Attendance and audit support spreadsheet-style column filters and sorting across all scoped records before pagination. `filters` is a JSON object keyed by whitelisted columns; each value can contain `text` (case-insensitive literal substring) and/or `values` (exact string selections). `sort` is a whitelisted column and `direction` is `asc`/`desc`. Responses include column `facets` (up to 500 options per column); text matching searches the full dataset. Attendance summary cards follow date/user scope and remain independent of column filters. Data scope remains enforced before filtering.

Migration `000009` adds `team_home_office` and the Spatie action permissions `home_office.view`, `home_office.assign`, and `home_office.cancel`. Owner automatically has access; assign the appropriate action permissions to a designated role or directly to a user. GET/POST `/api/team-home-office`, DELETE `/api/team-home-office/{id}`. POST fields: nullable `team_id` (null = all teams), `start_date`, `end_date` (inclusive, no one-year cap), and `reason` (5–2000 characters). All teams includes members of any team and excludes unassigned users. Current membership determines coverage at check-in; new members inherit active ranges. Any active matching assignment or approved personal home office sets new attendance mode to home office. Approved leave still prevents check-in. No attendance is fabricated. Assignments can overlap; cancellation stops that assignment, preserves its audit record and existing attendance, and does not override another active range or approved personal arrangement. Existing attendance is not retroactively changed.

Update: pull both repos, rebuild API/worker, run migrations, recreate API/worker and clear caches. No new Passport key setup, reseeding or face rebuild is needed if already on the Passport release.

### Action permissions upgrade

Migration `000010` splits coarse grants into view/create/update/delete and feature-specific actions, preserving existing role and direct grants. Legacy keys are marked deprecated and excluded from new grant choices; they no longer authorize endpoints. Team Leads receive `attendance.view_team` and `applications.view_team`. Team profile visibility and cancellation of others require separate grants. Attendance photos use separate photo permissions; migration `000011` grants team photos to the initial Team Lead role. Approval/rejection still requires an assigned reviewer and prohibits self-approval. Profile view/edit/password, check-in/check-out, enrollment/reset, request kinds, document access, work slots and access administration have independent permissions. UI actions and API endpoints both enforce them. Logout and basic interface controls remain available.

Update backend: `git pull --ff-only`, `docker compose build api worker`, `docker compose run --rm api php artisan migrate --force`, `docker compose up -d --force-recreate api worker`, then `docker compose exec api php artisan optimize:clear`. Update frontend with `git pull --ff-only` and restart `npm run dev`. Keep existing keys, volumes and accounts; do not reseed.

### Attendance photos

Migration `000011` grants the initial Team Lead role `attendance.photo_view_team`. Owners can view all attendance photos. For other roles/users, grant `attendance.view_team` plus `attendance.photo_view_team` for assigned teams, or `attendance.view_all` plus `attendance.photo_view_all` for everyone. Self photos use `attendance.photo_view_self`. The attendance table shows separate check-in/check-out photo buttons only for authorized rows with stored photos. Images are fetched with the HttpOnly authentication cookie and displayed in a responsive preview with the recorded timestamp; no public storage URL is exposed.

Attendance table columns are Date, Check-in, Check-out, Hours and Status. Status is Present, Absent or the approved leave type; missing exits remain visible under Check-out and in details. Past working days without attendance/approved leave are absent, excluding dates before account creation; today and future days are not marked absent. Select a row to inspect member, date, timestamps, workplace, leave, slot, hours and permitted photo previews.

### MiniVision attendance liveness update

One tap collects four distinct JPEG frames over approximately one second, without pose instructions. Each frame must have exactly one face matching enrollment and pass the two-model ensemble (live class wins and live score >= `FACE_LIVE_MIN_SCORE`, default 0.8). The saved check-in/out photo is bound to the first verified frame. Missing/bad responses, mismatches, duplicate frames, model failures and stale proofs fail closed. Pre-update matching-only proofs are rejected; reopen the camera after upgrading. No database migration or face re-enrollment is required.

Weights and source revision: `b6d5f04ad78778917853b25c778acef6d5626d15` from MiniVision Silent-Face-Anti-Spoofing, Apache-2.0; license/attribution in `face-service/minivision`. Models are loaded once and run on CPU with PyTorch 2.6 and OpenCV. Build requires GitHub weight access and CPU PyTorch packages; weights are not fetched during requests. No paid API is used. Existing HOG face boxes are used for model crops; validate this integration on target devices.

Update: pull both repos, add `FACE_LIVE_MIN_SCORE=0.8` to backend `.env`, run `docker compose build face api worker`, `docker compose up -d --force-recreate face api worker`, then `docker compose exec api php artisan optimize:clear`. Restart frontend and refresh the browser. Do not delete storage volumes or change APP_KEY. First build is larger due to CPU PyTorch.

Local checks: install face-service dependencies, CPU PyTorch, and `httpx`; run `python face-service/download_models.py` then `python -m unittest discover -s face-service -p 'test_*.py'`. These check weight loading, preprocessing/inference, policy, and API contracts with mocked face/PAD outcomes, not real attack accuracy. Actual Docker builds and physical-camera accuracy must be tested in the deployment environment.
