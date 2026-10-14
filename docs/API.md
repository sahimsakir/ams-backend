# API reference

Base: `http://localhost:8000/api`. JSON errors have `message` and, for validation failures, `errors`. Send `Accept: application/json` and `Authorization: Bearer TOKEN`. JSON mutations require `Content-Type: application/json`. Camera uploads use multipart form data.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | /login | email/password → user and 8-hour token; 5 attempts/min |
| GET | /me | current user and face enrollment status |
| POST | /logout | revoke current token |
| PUT | /password | current_password, password, password_confirmation; revoke all sessions |
| GET | /dashboard | current balances, today (or an unclosed previous shift), team assignments, pending review count |
| GET | /applications?page=1 | visible requests with all reviewer decisions; 50/page |
| POST | /applications | submit request; see fields below |
| POST | /applications/{id}/decision | decision: approved/rejected; rejection comment required |
| POST | /applications/{id}/cancel | cancel pending request/revoke approved home office |
| GET | /attendance?page=1 | scoped attendance; 100/page |
| POST | /attendance | JSON action: in/out, challenge_id: completed verification UUID; returns attendance timestamps |
| GET | /attendance/{id}/photo/{in\|out} | protected photo stream; 404 if pruned |
| GET | /teams | own memberships (all teams for owner) |
| POST | /teams | owner: name |
| PUT | /teams/{id}/members | owner: replace full members array of user_id/team_role |
| GET | /users | owner: people and enrollment status |
| POST | /users | owner: name, email, role, active, password (12+), optional designation/phone |
| PUT | /users/{id} | owner: same fields; password optional |
| POST | /face/challenges | purpose enroll/in/out, consent true; owner may supply user_id for another user’s enrollment |
| POST | /face/challenges/{id}/step | enrollment or attendance head turn: nonce plus frames[] (4–16 JPEG frames, ≤512 KiB each) |
| POST | /face/challenges/{id}/capture | attendance only: multipart nonce plus one photo (JPEG ≤1 MiB); matches face and starts head-turn prompt |
| POST | /face/enroll | challenge_id; enroll session subject after all three steps pass |
| DELETE | /users/{id}/face | owner: reset template and invalidate outstanding challenges |
| GET | /leave-types | list policy types |
| POST | /leave-types | owner: name, annual_limit (0–366) |
| GET | /calendar | working-day overrides |
| POST | /calendar | owner: date, name, is_working boolean; upsert by date |
| DELETE | /calendar/{id} | owner: delete override |
| GET | /audit?page=1 | owner: 100 entries/page |

Submission fields:

```json
{
  "kind": "leave",
  "leave_type_id": 1,
  "start_date": "2026-10-18",
  "end_date": "2026-10-19",
  "reason": "Family appointment",
  "contact": "Optional contact",
  "covering": "Optional colleague"
}
```

For home office use `kind: home_office`, omit leave_type_id, and set end_date to null for an ongoing arrangement. For overtime use `kind: overtime`, equal start/end dates, and minutes between 1 and 1440. The server computes leave working days and never trusts a frontend day count or face-match score.

Account roles: owner, manager, pm, team_lead, employee. Team roles: manager, pm, team_lead, employee. Only owner account role grants unrestricted administration.

General authenticated rate limit: 120 requests/minute. Face challenge and attendance endpoints: 45 requests/minute per user in addition to the general limiter.

## Camera protocol

Start `/face/challenges` with purpose `enroll`, `in` or `out` and consent true. The response contains `id`, `step`, `total`, current `pose`, `nonce`, `complete` and ISO `expires_at`. A new session revokes old sessions for the subject. Challenges expire after ten minutes; attendance capture leaves two minutes for the head-turn and completed proofs expire after two minutes.

Enrollment uses front, left and right. Send a fresh burst of at least four JPEG frames and the current nonce to `/face/challenges/{id}/step`. The server verifies identity and pose and only then advances the nonce/step. Once complete, send `challenge_id` to `/face/enroll`. An enrolled owner can specify `user_id` when starting enrollment for another active user, but employees cannot enroll others. The session is bound to both actor and subject. Existing verified profiles need an owner reset before replacement; legacy templates require matching guided upgrade or reset.

Attendance uses `capture` followed by one random left/right pose. Send one JPEG and nonce to `/face/challenges/{id}/capture`. A successful photo match returns `complete: false`, `step: 1`, a new nonce and the head-turn prompt. Its private original photo/landmark baseline is retained. Send four fresh frames with that nonce to `/step`. Only a matching face completing the requested landmark change yields a completed active proof. Then send `challenge_id` and the matching action to `/attendance`.

A photo match alone, skipped movement, repeated nonce, frozen/duplicate frames, old passive-classifier proof, expired session, changed profile, another user's proof or wrong action cannot create attendance. The backend never accepts client matching scores, completion flags or pose results. The single-frame anti-spoof classifier and its threshold were removed after a real webcam false rejection; the active head-turn check is mandatory instead. No eye closing is required. An unchanged photograph cannot satisfy the implemented landmark-change rule, but perspective distortion, multiple pose images, video replay and virtual cameras are not guaranteed to be rejected.

Attendance returns full server-assigned timestamps, for example `{ "message": "Checked in successfully", "attendance": { "id": 1, "user_id": 2, "work_date": "2026-10-13", "check_in": "2026-10-13T03:15:30.000000Z", "check_out": null, "mode": "office" } }`. Dashboard/list use the same ISO format. Private paths, matching scores and capture hashes are omitted. Exact prior attendance-photo reuse is rejected.

## Mandatory first enrollment

Authenticated users without a verified profile receive 403 with `face_enrollment_required: true` on workspace routes. Only `/me`, `/logout` and face challenge/capture/enrollment routes remain accessible; attendance still requires enrollment. The frontend automatically displays an enrollment dialog with no close button or workspace navigation. Owners must enroll themselves before managing other users. An owner-assisted enrollment unlocks the subject on subsequent login.
