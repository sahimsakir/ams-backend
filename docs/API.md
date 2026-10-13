# API reference

Base: `http://localhost:8000/api`. JSON errors have `message` and, for validation failures, `errors`. Send `Accept: application/json` and `Authorization: Bearer TOKEN`. JSON mutations require `Content-Type: application/json`. Camera uploads use multipart form data.

| Method | Path | Purpose |
| --- | --- | --- |
| POST | /login | email/password → user and 8-hour token; 5 attempts/min |
| GET | /me | current user and face enrollment status |
| POST | /logout | revoke current token |
| PUT | /password | current_password, password, password_confirmation; revoke all sessions |
| GET | /dashboard | current balances, today, team assignments, pending review count |
| GET | /applications?page=1 | visible requests with all reviewer decisions; 50/page |
| POST | /applications | submit request; see fields below |
| POST | /applications/{id}/decision | decision: approved/rejected; rejection comment required |
| POST | /applications/{id}/cancel | cancel pending request/revoke approved home office |
| GET | /attendance?page=1 | scoped attendance; 100/page |
| POST | /attendance | multipart action: in/out, consent: 1, photo: JPEG/PNG ≤4 MiB |
| GET | /attendance/{id}/photo/{in\|out} | protected photo stream; 404 if pruned |
| GET | /teams | own memberships (all teams for owner) |
| POST | /teams | owner: name |
| PUT | /teams/{id}/members | owner: replace full members array of user_id/team_role |
| GET | /users | owner: people and enrollment status |
| POST | /users | owner: name, email, role, active, password (12+), optional designation/phone |
| PUT | /users/{id} | owner: same fields; password optional |
| POST | /users/{id}/face | owner: multipart photo and consent: 1 |
| DELETE | /users/{id}/face | owner: remove biometric template |
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

General authenticated rate limit: 120 requests/minute. Face enrollment and attendance capture: 10 requests/minute in addition to the general limiter.
