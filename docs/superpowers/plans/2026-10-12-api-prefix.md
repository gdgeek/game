# Unified API Prefix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Route all business APIs through checkin.voxel.cn/api while preserving backend paths, authorization, Session isolation and safe B0/B1 failover.

**Architecture:** Extend the existing two entrypoint renderers with a raw-URI prefix adapter and fixed Session aliases. Move Web and MP request bases to the prefixed routes, leaving legacy compatibility and QR origin protocol intact.

**Tech Stack:** NGINX 1.28, POSIX shell/envsubst, Python unittest HTTPS fixtures, Vue/TypeScript/Vitest, UniApp WeChat.

---

### Task 1: NGINX gateway and fixed aliases

Files: dockerfile/40-configure-frontends.sh; dockerfile/50-configure-miniprogram.sh; dockerfile/tests/test_frontend_proxy.py; dockerfile/tests/test_miniprogram_failover.py; dockerfile/tests/README.md; dockerfile/admin.compose.yaml.

- [ ] Add failing integration cases for `/api/v2/devices/42?x=a%2Fb&x=2`, `/api/v1/common/test`, unknown business paths, POST/PUT/DELETE body preservation and both legacy entrypoints.
- [ ] Add failing cases proving `/api/health` fixed GET /health without credentials/body/arguments/override, audited prefixed GET failover, and no write replay.
- [ ] Add failing fixed-route Session/CSRF tests for `/api/backends/b0/admin-api/` and b1 plus bare `/api/admin-api/` pinned B0; Cookie Path stays isolated. Add debug/gii/source-file denial cases under /api.
- [ ] Run tests first and confirm expected missing-prefix failures. Implement the smallest renderer changes: raw request URI map strips only `/api`, optional-prefix audited read match, generic /api location, shared safe health block, fixed Session alias locations and internal deny rules. Preserve legacy paths.
- [ ] Run `PYTHONDONTWRITEBYTECODE=1 NGINX_TEST_BINARY=/private/tmp/game-frontend-nginx-test-build/nginx-1.28.3/objs/nginx python3 -m unittest discover -s dockerfile/tests -p 'test_*.py' -v` (escalated for local fixture sockets); no skips. Update mapping docs and image tag `checkin-frontend:20261012-api-1`.

### Task 2: Web and mini-program clients

Files Web: frontend/src/lib/api.ts; affected tests in frontend/src/lib. Files MP: .env.development; .env.production; src/config/index.ts; config tests; src/api/scan-login.ts; scan-login tests; README.md.

- [ ] Web tests expect production target `/api/backends/${backend}/admin-api`; confirm RED, change only production request base; keep demo `/admin-api` and runtime manifest unchanged. Run Web tests, typecheck and api build.
- [ ] MP config tests expect `https://checkin.voxel.cn/api/v2`; QR approval tests expect checkin target URLs under /api/v2 and direct origins under /v2; confirm RED, implement conditional trusted-origin API base and config changes. Keep canonical-origin QR validation and no client retries. Run MP tests/typecheck/lint/build.
- [ ] Independent spec review then quality review of both repositories; fix any material issues and rerun affected checks.

### Task 3: Release and live acceptance

- [ ] Commit/stage only intended files in isolated worktrees. Push root develop and await success, merge into clean remote-main promotion worktree and run same meaningful checks, push/main CI; then remote-publish promotion/checks/push/CI. MP main push/CI separately. Never force/reset/rebase.
- [ ] Build frontend tar whitelist from verified dist, template and scripts, upload via Portainer F endpoint2 image build, update stack24 image only, deploy without re-pull and verify healthy.
- [ ] Probe new health 200, unauthenticated API 401, old health 200, fixed aliases reachable with separate Session. Do not stop real backends or mutate devices.
- [ ] WeChat official Developer Tools uses urlCheck=true; actual login and 3 own devices plus B0/B1 fresh Web QR confirmation. Upload version `0.3.20261012` only after success; report upload separately from formal platform review/release.
- [ ] Save screenshots, update release journal with exact SHAs/CI/prod/MP evidence and remaining platform step, clean generated promotion worktrees, preserve unrelated original dirt.
