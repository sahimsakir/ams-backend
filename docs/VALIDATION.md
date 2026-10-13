# Validation report

## Completed

- Laravel/PHP feature suite: **21 tests, 59 assertions passed** under PHP 8.4.12 with in-memory SQLite.
- TypeScript strict compile and Vite production build: passed.
- Composer manifest validation: passed; composer.lock included.
- Python syntax compilation: passed for the face service and environment generator.
- Browser walkthrough against the actual Laravel API using a separate temporary SQLite database: owner login, employee creation, team creation, membership assignment, logout/login as employee, leave submission, owner fallback approval, and mobile layout.
- No browser JavaScript exceptions in that walkthrough; no horizontal overflow at 390px viewport width.
- Desktop dashboard and mobile requests screens visually inspected. No synthetic demo records are included in the distributed database (the package contains no populated database).
- Named rate limiters tested to ensure authenticated API traffic cannot consume the login quota.

## Not verified here

- Docker image builds and full Compose startup (Docker runtime unavailable).
- PostgreSQL-specific migrations, locking under concurrent load, and production SMTP delivery.
- Python/dlib image build and inference on real employee photos; real camera capture, face threshold calibration, accuracy and latency.
- Liveness is not implemented, so presentation attacks remain a known limitation.
- Production deployment, load testing and backup restoration.

Run the documented Docker setup and local acceptance tests before deploying. The automated face-related tests mock the encoding service and verify Laravel’s matching/authorization behavior; they do not measure model accuracy.
