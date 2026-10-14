# Validation report — guided camera update

## Passed in this workspace

- Laravel/PHP suite: **42 tests, 265 assertions**, PHP 8.4.12 with in-memory SQLite.
- Tests cover three enrollment checks, self-enrollment and owner enrollment, old-template upgrade, removal of owner photo enrollment, enrollment required for attendance, incomplete/expired/reused/wrong-user/wrong-purpose proof rejection, frozen frames, nonce reuse, face mismatch, service failure, profile reset, duplicate attendance, overnight checkout and ISO timestamp serialization.
- Existing leave, approval, roles and login-rate-limit tests still pass.
- Seven Python motion-rule tests pass: direction requirements, centered/open-eye front views, open/closed/open ordering, rejected static eyes, rejected head-turn-as-blink, final front view and scale-independent eye ratios.
- Five passive-classifier tests pass for normalization, class order, malformed probabilities and fail-closed model files. The real pinned ONNX model was loaded on CPU and a synthetic-input inference completed; this is a runtime smoke check, not an accuracy measurement.
- PHP tests reject low/missing/invalid anti-spoof scores, unavailable inference, reused photos and old motion-only proofs.
- Python face-service syntax compilation and Laravel Pint formatting pass.
- TypeScript strict compile and Vite production build pass.
- Four Playwright browser tests pass using a simulated camera and mocked API: three-step self-enrollment with failed-step retry, single-photo check-in/checkout and full date/time rendering; modal layout and camera track cleanup at 320, 390, 768, 1024, 1440 and 1920 px; camera-permission recovery instructions; owner enrollment for a selected user. No JavaScript exceptions were observed in the enrollment/attendance and layout checks.
- Mobile camera modal screenshot visually reviewed.
- HTTPS Vite `/api` proxy smoke check passed against a loopback test server. LAN binding could not be exercised in this sandbox; real phone access remains unverified.

## Not verified here

- Docker image builds and full Compose startup (Docker is unavailable).
- PostgreSQL-specific migrations, locking under concurrent load, and production SMTP delivery.
- Real employee images, dlib inference accuracy/latency, physical camera capture, iOS/Android devices, head-pose thresholds across facial geometry or assistive needs.
- Presentation-attack resistance against print attacks, screen/video replays, virtual cameras or deepfakes. A trained passive RGB anti-spoof model is implemented for attendance, but its rejection rate on your employees/cameras has not been measured and it is not a certified capture/PAD system.
- Production deployment, load testing and backup restoration.

The Laravel tests mock the internal face service. Python motion/PAD tests use synthetic inputs, and browser tests use a fake webcam and mocked API. These checks validate the application protocol and UI, not biometric accuracy or real-device compatibility. Test the documented HTTPS setup with your actual phones, lighting and employees before rollout. No real employee biometric data was used or included in either repository.


## Webcam false-rejection threshold update

The real-face acceptance threshold default was changed from 0.95 to the upstream demo's reference 0.60. Three added API tests verify that a valid 0.70 score can pass, 0.59 remains rejected, and configuration cannot disable the classifier. This is a code/decision-boundary check, not camera calibration. Private score/threshold/face-distance diagnostics were added without exposing scores or biometric data in API responses. The user's actual live capture has not been tested here; follow the README to collect its score if rejection persists. Reducing the cutoff trades fewer false rejections for potentially more accepted attacks, so test genuine and physical print/screen samples in the deployment environment.
