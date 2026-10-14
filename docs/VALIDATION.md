# Validation report — guided camera update

## Passed in this workspace

- Laravel/PHP suite: **35 tests, 200 assertions**, PHP 8.4.12 with in-memory SQLite.
- Tests cover three enrollment checks, self-enrollment and owner enrollment, old-template upgrade, removal of owner photo enrollment, enrollment required for attendance, incomplete/expired/reused/wrong-user/wrong-purpose proof rejection, frozen frames, nonce reuse, face mismatch, service failure, profile reset, duplicate attendance, overnight checkout and ISO timestamp serialization.
- Existing leave, approval, roles and login-rate-limit tests still pass.
- Seven Python motion-rule tests pass: direction requirements, centered/open-eye front views, open/closed/open ordering, rejected static eyes, rejected head-turn-as-blink, final front view and scale-independent eye ratios.
- Python face-service syntax compilation and Laravel Pint formatting pass.
- TypeScript strict compile and Vite production build pass.
- Four Playwright browser tests pass using a simulated camera and mocked API: three-step self-enrollment with failed-step retry, three-step check-in/checkout and full date/time rendering; modal layout and camera track cleanup at 320, 390, 768, 1024, 1440 and 1920 px; camera-permission recovery instructions; owner enrollment for a selected user. No JavaScript exceptions were observed in the enrollment/attendance and layout checks.
- Mobile camera modal screenshot visually reviewed.
- HTTPS Vite `/api` proxy smoke check passed against a loopback test server. LAN binding could not be exercised in this sandbox; real phone access remains unverified.

## Not verified here

- Docker image builds and full Compose startup (Docker is unavailable).
- PostgreSQL-specific migrations, locking under concurrent load, and production SMTP delivery.
- Real employee images, dlib inference accuracy/latency, physical camera capture, iOS/Android devices, head-pose thresholds across facial geometry or assistive needs.
- Presentation-attack resistance against print attacks, screen/video replays, virtual cameras or deepfakes. Geometric motion checks are implemented, but there is no certified PAD model or measured spoof-rejection rate.
- Production deployment, load testing and backup restoration.

The Laravel tests mock the internal face service. Python motion tests use synthetic geometry, and browser tests use a fake webcam and mocked API. These checks validate the application protocol and UI, not biometric accuracy or real-device compatibility. Test the documented HTTPS setup with your actual phones, lighting and employees before rollout. No real employee biometric data was used or included in either repository.
