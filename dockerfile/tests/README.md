Run the frontend proxy checks from the repository root:

```sh
PYTHONDONTWRITEBYTECODE=1 NGINX_TEST_BINARY=/path/to/nginx python3 -m unittest discover -s dockerfile/tests -p 'test_frontend_proxy.py' -v
```

The renderer checks require `sh` and `envsubst`; the integration checks also need a real Nginx binary with HTTPS proxy support and the OpenSSL CLI. `OPENSSL_TEST_BINARY` can override the OpenSSL executable. Without a Nginx executable, unittest explicitly skips the integration checks. A skipped run does not validate proxy behavior.

All traffic goes to temporary HTTPS servers on localhost. The suite creates a temporary CA, trusted and invalid certificates, static frontend files, and Nginx process state. It relocates only the shipped config's listen address, static root, and CA bundle into temporary paths, and refuses to start Nginx with a non-local upstream. It does not use deployment credentials, require Docker, or change system configuration.

The entrypoint requires at least one `APP_API_N_URL` HTTPS origin; the image has no built-in backend defaults. Slot numbers must be canonical positive integers within JavaScript's safe integer range. Slots are sorted numerically and preserve route identities through gaps: slots 1 and 3 produce `b0` and `b2`. Empty configured URLs, invalid slots, URL credentials, paths, query strings, fragments, invalid ports, and oversized hostnames or labels prevent startup. The generated `/runtime-config.json` includes only each backend's ID, display name and host (with normalized non-default ports), uses `no-store`, and emits no ETag or Last-Modified validator. The manifest is a separate file in the static frontend directory so its size is not limited by Nginx directive arguments. Tests relocate its output with `NGINX_FRONTEND_RUNTIME_CONFIG_PATH`. Homepage HTML uses `no-cache`.

The checks cover one backend, three backends, sparse slots and a 101-backend Nginx configuration; the runtime manifest; API path/query/method/body forwarding; Host and TLS SNI; certificate chain and hostname validation; session and CSRF cookie path isolation across three upstreams; no retry or backend switching on failure; fixed read-only health probes; and unknown or legacy route rejection.

If Nginx is not installed, build an official source release in a temporary directory and point `NGINX_TEST_BINARY` at its `objs/nginx`; installation is unnecessary. For example, on an Apple Silicon Mac with existing Homebrew OpenSSL and PCRE2 libraries:

```sh
./configure --prefix=/private/tmp/frontend-nginx-runtime --with-http_ssl_module --with-http_realip_module \
  --with-cc-opt='-I/opt/homebrew/opt/openssl@3/include -I/opt/homebrew/opt/pcre2/include' \
  --with-ld-opt='-L/opt/homebrew/opt/openssl@3/lib -L/opt/homebrew/opt/pcre2/lib'
make -j4
```

Download source releases from [nginx.org](https://nginx.org/en/download.html). For Linux, use existing OpenSSL/PCRE development libraries with `./configure --with-http_ssl_module --with-http_realip_module`; run the test binary as an unprivileged user.

Mini-program primary/backup checks run alongside the Web checks:

```sh
PYTHONDONTWRITEBYTECODE=1 NGINX_TEST_BINARY=/path/to/nginx python3 -m unittest discover -s dockerfile/tests -p 'test_*.py' -v
```

`50-configure-miniprogram.sh` runs after the Web renderer. It is disabled by default (`APP_MP_API_ENABLED=0`) and leaves the Web configuration untouched. To enable it, configure all four variables:

```yaml
APP_MP_API_ENABLED: "1"
APP_MP_API_PRIMARY_URL: "https://x.b0.01xr.com"
APP_MP_API_BACKUP_URL: "https://x.b1.01xr.com"
APP_MP_API_UPSTREAM_HOST: "x.4mr.cn"
```

Both HTTPS origins are validated as hostnames with optional ports, without credentials, paths, query strings or fragments. The separate upstream host supplies both HTTP Host and TLS SNI; certificates must chain to the container CA bundle and match this name. Invalid input prevents startup before replacing the rendered configuration. Dynamic upstream DNS uses Docker's `127.0.0.11` resolver, with a 30-second cache. `APP_MP_API_RESOLVER` accepts a validated IPv4 address with optional port for deployments using another DNS server and for local fixtures. Nginx 1.27.3 or newer is required for open-source dynamic upstream resolution; the image uses Nginx 1.28.

The shared primary/backup pool uses `max_fails=1` and `fail_timeout=10s`. The two-attempt budget assumes each backend origin resolves to one IPv4 A address. If the primary later has multiple A addresses, Nginx can spend both attempts on primary addresses before reaching the backup; verify the real DNS answers from the deployed frontend container and preserve this condition when changing backend DNS. A transport failure, timeout, or 502/503/504 on an audited read can try the backup, with at most two total attempts and a 25-second retry window. The existing 5-second connection and 20-second send/read timeouts still apply to each attempt; the retry window controls whether a further attempt may start, rather than imposing a total response deadline. Recovery is passive: a request after the quarantine interval probes the primary again. Nginx compares integer seconds inclusively, so the earliest eligible request can occur approximately 10–11 seconds after failure.

Only query-free GET/HEAD for `/v2/devices`, `/v2/devices/manage` and `/v2/devices/<positive integer>`, plus GET/HEAD for `/v2/server/scenes`, are allowlisted for retry. All device requests with a query string use a single attempt: `Device::extraFields()` can create a missing setup during `expand=setup`, so even ordinary device queries are conservatively excluded. The scene helper ignores incoming query parameters and reads a fixed external URL. The same allowlist accepts an optional literal `/api` prefix. These routes have been checked against their controllers, models and helpers. Device authentication can normalize the user's derived role; that idempotent metadata correction is distinct from device/configuration creation. The original URI must match, so encoded aliases do not broaden the allowlist. Any nonempty `X-Http-Method-Override` also forces a single attempt, while preserving the header for normal backend semantics. Yii checks POST form `_method` first, then this override header, then the wire method; it does not read query `_method` or JSON bodies for method selection. All real POST requests are already excluded from retry, including tunneled PUT/DELETE forms. All other methods/routes use `proxy_next_upstream off`, including side-effecting GET `/v2/server/info` and `/v2/server/refresh`, WeChat code exchange, payment, ticket approval, PUT and DELETE. They are never replayed, including connection failures before a request body is sent. Transport failures still quarantine the failed peer for subsequent independent requests; HTTP error responses on these non-retried routes are returned unchanged and do not themselves trigger a retry or quarantine. No `non_idempotent` option is enabled.

`/api/<path>` removes only the literal `/api` prefix from the original request URI and forwards business paths across all API versions (for example `/api/v2/devices` → `/v2/devices`, `/api/v1/common/test` → `/v1/common/test`). Encoded path bytes, repeated slashes, query parameters, methods, bodies and Bearer Authorization survive forwarding. `/v2/` remains compatible and preserves its original path and query. Both stateless routes remove browser Cookie and hide upstream Set-Cookie. `/api/health` and the compatible `/mp-health` permit GET/HEAD, send a fixed GET `/health` with no query/body/Authorization/Cookie/method-override header, and use the same primary/backup pool. The fixed Web `/api/backends/<id>/admin-api/` aliases and compatible `/backends/<id>/admin-api/` routes continue using their selected backend even when the stateless pool has quarantined that backend. Their Cookie Paths are `/api/backends/<id>/` and `/backends/<id>/` respectively. Every configured slot also receives a fixed `/api/backends/<id>/health` alias. `/api/admin-api/` is a separate fixed B0 alias using `APP_API_1_URL` and the existing Web Host/SNI contract, with Cookie Path `/api/admin-api/`; it never retries or joins the primary/backup pool. If slot 1 is absent this alias returns 404. Nginx normalizes Session routing before stateless forwarding, so `/api/%61dmin-api/` stays on the fixed B0 route. The slashless `/api/admin-api` and unknown `/api/backends/` routes return 404. The existing `/healthz` remains a frontend-only process probe.

The new suite uses only local HTTPS servers, temporary certificates and a local UDP DNS fixture that answers only two test hostnames. It covers actual DNS resolution, Host/SNI, valid/invalid certificates, primary selection, connection/read timeout and gateway failure, backup selection, passive recovery, finite failure with both peers unavailable, application errors, GET/HEAD allowlisting, method-override and device-query exclusion, no mutation replay, Cookie/Authorization boundaries, health probe restrictions, and fixed Web route isolation. It never stops production backends or uses real credentials. Nginx integration tests are explicitly skipped if no executable is available; skipped runs do not validate failover.

The generic `^~ /api/` location checks normalized paths itself because Nginx skips server-level regex locations for this prefix. Dot paths, source/config extensions (including `.php` PATH_INFO), selected configuration JSON basenames, and `debug`/`gii` path segments return 404 without contacting a backend; encoded aliases receive the same checks. Encoded gateway-prefix aliases do not enter the generic pool. The proxy only forwards API requests: it does not rewrite Swagger or other HTML embedded absolute links, alter Yii `baseUrl`, or change existing payment callback addresses. Safe root-relative HTTP redirects and absolute redirects to the configured origins or shared Host retain `/api`; external origins stay unchanged.

The prefix tests additionally verify original encoded suffixes and repeated slashes for GET/HEAD/POST/PUT/DELETE/PATCH/OPTIONS, business paths outside v2, exact allowlisting, query/override/mutation no-replay, fixed Session cookie isolation (including encoded admin aliases and missing B0), credential-free health aliases, source/debug denial, HTTP redirect prefix preservation, and old-client compatibility. The fixture records the raw HTTP request target so Python's leading-double-slash normalization cannot hide forwarding regressions.

The generic gateway rewrites known HTTPS upstream and root-relative Location headers under the gateway prefix. HTTP absolute and protocol-relative Location headers remain unchanged. This API routing change does not make upstream HTML applications prefix-aware.
