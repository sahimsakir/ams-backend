# Validation report — brief active attendance check

## Passed

- PHP 8.4.12 / SQLite feature suite: **41 tests, 267 assertions**. Includes enrollment/owner enrollment, access gating, face mismatch, required movement, incomplete and expired proofs, invalid baseline/service response, unavailable verifier, reused photos, old passive-proof rejection, nonce reuse, duplicate attendance and overnight timestamps. Existing application/approval/login tests pass.
- Regression test uses the reported former classifier score 0.0012 and face distance 0.1903: after a matching capture and successful active head turn, attendance succeeds. A photo match alone or failed movement creates no record.
- Seven Python motion-rule tests: static/wrong pose rejected, required direction, front/eye geometry rules and scale-independent ratios. No passive-classifier model remains in the runtime.
- Four Playwright tests use a fake camera/mocked API for mandatory enrollment, automatic post-capture head-turn flow through check-in/out, full timestamp display, responsive layouts from 320 to 1920 px, camera cleanup, denied permission and owner enrollment.
- TypeScript strict compilation, Vite build, PHP formatting, Python syntax and git whitespace checks pass.

## Limits

- Face model accuracy, the user's actual webcam after this update, real iOS/Android devices and physical print/screen attack rejection are not measured by these tests.
- Docker builds, complete Compose startup, PostgreSQL concurrency and production mail delivery remain unverified in this workspace.
- Active landmark checks are not certified PAD. Recordings, multiple pose images, perspective-distorted photographs and virtual cameras may defeat them. An API cannot prove browser-frame physical origin.

The former passive RGB model was removed because it blocked a real reported camera input. That blocker is absent from the new path; there is no unconditional attendance bypass. Test the brief motion check with your real employees/cameras before rollout.
