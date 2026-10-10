# Health Endpoint Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development or superpowers:executing-plans to implement the checklist with red/green verification.

**Goal:** Provide anonymous `/health` requests and container health status that reflect database and Redis availability.

**Architecture:** A Yii component probes dependencies and reads optional build metadata. A public controller maps the result to HTTP 200 or 503 through the production strict URL rules. CI embeds the revision and build time; Docker probes the endpoint locally.

**Tech Stack:** PHP 8.4, Yii 2, PDO, yii2-redis, Codeception, GitHub Actions, PHP Apache Docker image.

## 1. Establish failing behavior

Files: `api/tests/unit/components/HealthServiceTest.php`, `api/tests/unit/components/UrlManagerTest.php`, `api/tests/functional/HealthCest.php`.

- [x] Test real SQLite `SELECT 1` and failure, Redis `PING` replies/failure, optional build metadata, anonymous GET/HEAD with actual production URL rules, HTTP 503 and empty HEAD bodies.
- [x] Run `php vendor/bin/codecept run unit components/HealthServiceTest.php --no-interaction` in an isolated API copy; confirm the service is absent.
- [x] Run `php vendor/bin/codecept run functional HealthCest.php --no-interaction`; confirm `/health` returns 404 before implementation.

## 2. Implement the contract

Files: `api/components/HealthService.php`, `api/components/UrlManager.php`, `api/controllers/HealthController.php`, `files/api/config/web.php`.

- [x] Implement `HealthService::check(): array`: database is required, Redis is checked when configured, each result contains `status` and integer millisecond `responseTime`, exceptions return only `Service unavailable`. Aggregate as `healthy`/`unhealthy`, with ISO 8601 `timestamp` and optional `gitCommit`/`version` files.
- [x] Register the component and explicit routes:

```php
'GET health' => 'health/index',
'HEAD health' => 'health/index',
```

- [x] Set JSON format, HTTP 200/503 and `Cache-Control: no-store`; return an empty body for HEAD while executing the same checks.
- [x] Preserve Redis caching for normal requests. Use a custom URL manager to bypass route caching only when the request path is `health`, including initialization during debug/gii bootstrap. Verify actual cache reuse for normal requests and JSON 503 with unavailable Redis for health requests.
- [x] Re-run both focused test commands and require zero failures.

## 3. Connect the image and document usage

Files: `.github/workflows/ci.yml`, `.gitignore`, `dockerfile/Dockerfile`, `docs/HEALTH.md`.

- [x] Add `pdo_sqlite` to the test PHP extensions. Before image construction write metadata:

```sh
printf '%s\n' "$GITHUB_SHA" > api/GIT_COMMIT
TZ=Asia/Shanghai date '+%Y-%m-%d %H:%M:%S' > api/BUILD_TIME
```

- [x] Ignore both generated files. The existing `COPY ./api/` and `context: .` include them in the image.
- [x] Add a PHP HTTP health probe with interval 30s, timeout 15s, start period 30s and retries 3. Validate exits against healthy, unavailable, invalid JSON and connection failure fixtures.
- [x] Document curl usage, status codes, optional metadata, dependency checks and container behavior.

## 4. Verify the change

- [x] Run the complete unit and functional suites in an isolated copy with the repository's generated test configuration, then PHP syntax checks, actionlint and `git diff --check`.
- [x] Independently review production routing, dependency probes, failure handling and Docker/CI integration. Address any material findings and rerun affected checks.
- [x] Prepare the verified implementation on `develop` for the user-authorized CI release.

Verification: local unit 34 tests / 167 assertions, functional 16 tests / 75 assertions; PHP syntax, actionlint, Docker probe fixtures and independent production-bootstrap review passed. Local database tests used SQLite; CI also verifies the MariaDB-backed healthy endpoint.
