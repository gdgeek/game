# Automatic backend proxy and JWT authentication plan

**Goal:** Apply the actual numbered-upstream proxy and JWT authentication methods from `gdgeek/vue3.7dgame.com` / `gdgeek/api.7dgame.com`. Keep x.b0/x.b1 and remove user backend selection.

**Architecture:** Nginx generates request distribution with `split_clients` and `map`, dynamic DNS and passive read failover. The browser uses one same-origin `/api/admin-api`, sends Bearer JWT, persists tokens and refreshes them before expiry. PHP Session and CSRF are not used for the production admin API. Each node uses ES256 with the main API's existing cloned jwt-key.pem/jwt-key-pub.pem signing material and shares dedicated Redis admin token records; existing business cache settings stay unchanged. Write requests are not automatically replayed.

## Confirmed scope
- [x] Read actual reference proxy entrypoint/template and frontend authClient/request/store code.
- [x] Read main API user config, AuthController, IdentityService, SessionService, RefreshToken and JWT validation chain. API disables PHP Session; Redis stores refresh token records.
- [x] User explicitly selected JWT for password login, QR login, refresh and logout (2026-10-16).
- [x] APP_API_1_URL / APP_API_2_URL retain https://x.b0.01xr.com and https://x.b1.01xr.com.
- [x] Remove manual backend selector and browser-selected node persistence.
- [x] Numbered weighted Nginx generation, common Host/SNI, dynamic DNS, read failover and no write replay validated with real Nginx.
- [x] Dedicated game-admin JWT audience/identity domain and strict signature/time checks cannot be used as v1/v2 WeChat Bearer credentials.
- [x] Access token lifetime at most 3 hours; refresh cannot extend the existing total 8-hour login lifetime. Redis stores refresh hashes and stable random login IDs. Atomic refresh rotation; logout revokes the login ID and immediately rejects its access tokens.
- [x] Shared atomic password login limiting retains a total of five attempts per IP/15 minutes across nodes; owner locks verify and renew ownership before database commit.
- [x] Password role remains admin; QR role remains device_manager with real-time user existence and control.user_id checks.
- [x] QR browser ownership moves from PHP Session to independent random X-Admin-Scan-Owner proof; never put proof in URL or QR. Database state transition and distributed owner lock prevent duplicate consume or active replacement tickets. Existing mini-program QR approve protocol stays compatible.
- [x] Stable admin proxy omits Cookie and upstream Set-Cookie while forwarding Authorization and owner proof; legacy diagnostic aliases remain compatible. Existing mini-program source guards and read allowlist stay covered.
- [x] Frontend token validation/persistence, single-flight refresh, stale response generation guards, missing/expired/revoked token handling, QR lost-response recovery and demo mode verified.
- [x] Full backend/Web/proxy tests and production build; independent review and fix findings.
- [ ] develop CI -> main CI -> publish CI gates, then frontend whitelist image build and Portainer deployment. Both backend nodes must have shared token storage before automatic Web is enabled.
- [ ] Verify cross-node login/refresh/logout, CSRF removal and token-domain isolation, QR create/status/cancel, frontend no selector and existing mini-program routes using isolated test clients; no real device writes or production outage.
- [ ] Record hashes, CI/image/evidence, preserve original dirty worktree, archive temporary worktree.

## API contract

`GET /admin-api/session` accepts optional Bearer. Missing Bearer returns `{user:null,scanLoginEnabled,authMode:"jwt"}`; an invalid supplied token returns 401. It does not create a PHP session or CSRF cookie.

`POST /login` accepts `{username,password}`. `POST /refresh` accepts `{refreshToken}`. Login, refresh and QR consume return `{user,token:{accessToken,refreshToken,expires,refreshExpires},authMode:"jwt"}` with ISO 8601 timestamps. `POST /logout` accepts `{refreshToken}` and returns `{user:null,authMode:"jwt"}`. All use the same `/admin-api` base.

QR browser operations and password login/logout carry `X-Admin-Scan-Owner`, a 64-character cryptographically random hex value stored in tab sessionStorage. The existing QR approval secret remains separate. A lost consume response cannot be reconciled through an old cookie; the user can explicitly refresh the QR and authenticate again, without replaying consume.

## Implementation ownership

- Auth agent: AdminAuthService, AdminTokenService/Store, AdminController, web config/routes, CI and auth/token tests.
- QR agent: AdminScanLoginService and AdminScanLoginTest.
- Proxy/frontend agent: Nginx generator/template/tests and Vue auth client/UI/tests.
- Root: source comparison, integration/independent review, documentation, verification and release/deployment.

The previously prepared shared PHP Session approach was not released and has been withdrawn in favor of the user's JWT choice.

## Local verification (2026-10-16)

- Real Nginx 1.28.3: 58 proxy tests passed, including TLS, distribution, safe read failover and write single-send.
- Backend unit and functional suites, real Redis ES256/rotation/limit/owner-lock tests, Vue tests and production typecheck/build passed.
- Existing mini-program: 215 tests passed, no source change.
- Independent review probes cover account switching, stale responses, storage failure, refresh flight isolation and simultaneous cross-tab refresh; findings fixed and probes passed.
- Reference main API production signing files and shared Redis layout were inspected; no key generation or change to existing business JWT/Redis components.
