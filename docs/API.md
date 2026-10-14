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
| POST | /face/challenges/{id}/step | multipart nonce plus frames[] (4–16 live JPEG frames, ≤512 KiB each) |
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

## Guided camera protocol

Start a session with `POST /face/challenges` and `{ "purpose": "enroll", "consent": true }`. The response has `id`, zero-based `step`, `total`, current `pose`, `nonce`, `complete`, and ISO `expires_at`. Only the next required pose is returned. Enrollment requires exactly three steps: front, left, right. Attendance still requires front, one random left/right turn, and open/closed/open eyes.

For each prompt, capture a fresh burst from the live camera. Send the returned nonce and `frames[]` to the step endpoint. At least four frames are required; the frontend sends sixteen for open/closed/open eyes. The server checks one face throughout, identity, landmark movement, duplicate frames, session owner, nonce and expiry. It advances only after successful verification. Retry the same prompt on a 422; restart an expired session. A new session revokes all previous sessions for the target person, including sessions started by an owner. Sessions expire after ten minutes, and completed results expire after two minutes. Never trust client-provided match scores or completion flags.

Once `complete` is true, send its ID to `/face/enroll`, or `/attendance` with `action` matching the original purpose. A result is single use and bound to the enrolled template. Enrollment is self-service for every account role. An enrolled owner can pass `user_id` to start a guided enrollment for another active user; the owner must capture the selected person in front of the camera with their consent. The session is bound to both the owner and the target; employees cannot enroll other people, and an enrollment proof cannot be used for attendance. The old single-photo enrollment endpoint remains removed. Existing version-1 templates must complete guided enrollment while matching their old template, or an owner can reset the template. Replacing an already verified template requires an owner reset.

Attendance mutation responses include `{ "message": "…", "attendance": { "id": 1, "user_id": 2, "work_date": "2026-10-13", "check_in": "2026-10-13T03:15:30.000000Z", "check_out": null, "mode": "office" } }`. Dashboard and list endpoints use the same UTC ISO format. Dates/times are assigned by the server; work dates and display use Asia/Dhaka. Private paths and face match scores are omitted.

This protocol has geometric motion checks, not certified presentation-attack detection. A normal uploaded or frozen photo cannot satisfy the supported flow; a manipulated recording or virtual camera may defeat it. The server cannot attest the physical source of browser camera frames. Do not describe it as photo/video spoof-proof.

## Mandatory first enrollment

Authenticated users without a verified face profile receive HTTP 403 with `{ "face_enrollment_required": true, "message": "Enroll your face to unlock your workspace." }` on workspace routes. Only `/me`, `/logout`, and the guided face challenge/enrollment endpoints remain accessible. This includes owners: they must enroll themselves before managing people or enrolling other users. Frontend displays a mandatory enrollment dialog automatically; it has no close button or workspace navigation. An owner-enrolled user can enter normally on their next login. Owners can reset a profile first if a verified template needs replacing.
