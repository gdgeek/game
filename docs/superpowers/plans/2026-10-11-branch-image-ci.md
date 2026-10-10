# Branch Image CI Implementation Plan

> **For agentic workers:** Use this checklist to implement and verify the approved branch publishing requirements.

**Goal:** Publish `wechat:develop`, `wechat:main`, and `wechat:publish` from the matching branches, with `wechat:latest` published only from `publish`.

**Architecture:** Adapt the reference repository's test → build and publish → publish notice flow to this application's PHP 8.4 and Codeception setup. Preserve the existing Tencent registry credentials, Dockerfile, and dependency set required by its development-mode entry point, combine duplicate builds, and leave runtime deployment to the external environment as the reference workflow does.

**Tech Stack:** GitHub Actions, PHP 8.4, Codeception, MariaDB 10.4, Redis 7, Docker Buildx, Tencent Container Registry.

## Tasks

- [x] Read `gdgeek/api.7dgame.com`'s default-branch workflow and check the current repository's secret names without reading values.
- [x] Modify `.github/workflows/ci.yml`: test all pushed branches and PRs into develop/main/publish, publish images only on pushes to those three branches, disable implicit latest, and explicitly enable latest only for publish.
- [x] Repair the workflow's test setup by copying console/test/params templates, writing isolated database configs, generating actors, using the test CLI for migrations, and propagating test failures.
- [x] Install application dependencies before the single Docker build, retain Buildx/GHA caching and existing TCR secrets, and replace Portainer deployment with the reference's publish notice.
- [x] Document the branch/tag mapping, required secrets, and external deployment behavior in `docs/CI.md`.
- [x] Run actionlint on the workflow and validate real metadata-action tag output for develop/main/publish.
- [x] Run the existing Codeception unit and functional suites in an isolated copy and record the database-environment limits.
- [x] Independently review the final diff, check whitespace and repository status, and report the exact verification performed.

## Validation results

- actionlint 1.7.12: no workflow or expression errors.
- Official GitHub expression evaluator: all 10 branch/event scenarios pass; only pushes to the three release branches publish images.
- Official Docker metadata-action v5: all six fixtures pass; develop/main produce one tag each, publish produces publish/latest, and PR fixtures produce no tags.
- PHP 8.4.7 / Codeception 5.3.3 in an isolated copy: unit 21 tests / 109 assertions and functional 10 tests / 23 assertions pass using SQLite in memory.
- Offline Composer installation in a fresh copy with no config directory completes, generates optimized autoload, and preserves the development dependencies needed by the existing entry point.
- Independent review caught and resolved the incompatibility between `--no-dev` and the current dev-mode container entry point.
- The local Docker daemon did not respond. MariaDB migrations and the full container build have not been executed locally; the actual GitHub Actions run must verify those steps.
