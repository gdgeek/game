# Unified /api gateway design

User approved preserving backend paths: `/api/v2/devices` → `/v2/devices`, `/api/health` → `/health`. All business API paths are forwarded under `/api/`, including paths outside v2. Preserve old `/v2/`, `/mp-health`, and fixed `/backends/<id>/...` routes for released clients.

## Routing and credentials

- Generic `/api/<path>` removes only the literal `/api` prefix and preserves original encoded suffix, query, method, body and Bearer. Use existing verified TLS/SNI and shared B0 primary/B1 backup pool. Query-free device reads and scenes retain their audited retry rules, matching original URI with optional literal `/api`; all other business requests remain single-attempt.
- `/api/health` reuses fixed credential-free GET `/health` health semantics, clears arguments/body/method override, and supports failover. GET/HEAD permitted; other methods rejected.
- `/api/admin-api/` is a fixed B0 Session alias, with isolated Cookie Path and no retry. Session requests never join the stateless primary/backup pool.
- Generate `/api/backends/<id>/admin-api/` and `/api/backends/<id>/health` fixed aliases alongside existing Web routes. Web production client uses the new alias; demo mode remains unchanged. Preserve backend identity, Session/CSRF isolation and backend selection behavior.
- Generic proxy continues to deny dot paths, source/config extensions, and backend debug/gii entry points. These are not business APIs. Static backend public assets can be forwarded, but HTML embedded absolute links are outside this API-prefix change; HTTP redirects should remain under the prefix where safe.
- Existing backend endpoint authorization remains unchanged. Unauthenticated requests must still be rejected by the backend.

## Mini-program

Set dev/prod/default API base to `https://c.4mr.cn/api/v2`. QR ticket origin remains the canonical root origin; new MP uses `/api/v2` when the trusted target is c.4mr.cn, and retains `/v2` for trusted direct backend targets. This avoids changing the QR protocol and keeps older uploaded packages functional. Do not add client retries or mix Web Session and Bearer.

## Release and acceptance

TDD verifies raw path/query and all HTTP methods, unknown business path forwarding, prefix-only removal, primary/backup selection, no mutation replay, cookie boundaries, fixed Session aliases, source/debug denial and legacy compatibility. Run both NGINX suites without skips, Web tests/typecheck/api build, MP tests/typecheck/lint/build. Independent spec review then quality review before release.

Promote root develop → main → publish with CI gates. MP main CI independently. Build/deploy F frontend with a new image tag, keeping current environment and fixed servers. No backend origin migration is needed. Verify `/api/health`, protected `/api/v2/devices`, old health compatibility, actual WeChat login/associated devices and B0/B1 real QR confirmation. Upload a new dated MP version only after verification. Formal platform review/release remains manual because browser site safety policy previously blocked the platform; do not circumvent.
