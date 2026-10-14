# Validation report — face-only attendance

## Passed

- PHP 8.4.12 / SQLite feature suite: **40 tests, 245 assertions**. Includes enrollment/owner enrollment, access gating, face mismatch, one-photo matching, incomplete and expired proofs, invalid service response, unavailable verifier, reused photos, old passive-proof rejection, nonce reuse, duplicate attendance and overnight timestamps. Existing application/approval/login tests pass.
- Regression test uses the reported face distance 0.1903: one matching photo can record attendance, without a baseline, movement or classifier score. Invalid/mismatching responses create no record.
- Seven Python motion-rule tests: static/wrong pose rejected, required direction, front/eye geometry rules and scale-independent ratios. No passive-classifier model remains in the runtime.
- Four Playwright tests use a fake camera/mocked API for mandatory enrollment, single-photo face-matching flow through check-in/out, full timestamp display, responsive layouts from 320 to 1920 px, camera cleanup, denied permission and owner enrollment.
- TypeScript strict compilation, Vite build, PHP formatting, Python syntax and git whitespace checks pass.

## Limits

- Face model accuracy, the user's actual webcam after this update, real iOS/Android devices and physical print/screen attack rejection are not measured by these tests.
- Docker builds, complete Compose startup, PostgreSQL concurrency and production mail delivery remain unverified in this workspace.
- Attendance performs no liveness or PAD check. A matching printed/screen photo may be accepted. Exact-byte hashes do not prevent photographed or re-encoded image replays.

At the user’s explicit request, attendance now matches a single photo with the enrolled face. The previous passive and head-turn checks are absent; authentication, enrollment, face match and proof validation remain mandatory. These tests do not establish physical presence or real-device matching accuracy.
