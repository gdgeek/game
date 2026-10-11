Run the frontend proxy checks from the repository root:

```sh
NGINX_TEST_BINARY=/path/to/nginx python3 -m unittest discover -s dockerfile/tests -p 'test_frontend_proxy.py' -v
```

The renderer checks require `sh` and `envsubst`; the integration checks also need a real Nginx binary with HTTPS proxy support and the OpenSSL CLI. `OPENSSL_TEST_BINARY` can override the OpenSSL executable. Without a Nginx executable, unittest explicitly skips the integration checks. A skipped run does not validate proxy behavior.

All traffic goes to temporary HTTPS servers on localhost. The suite creates a temporary CA, trusted and invalid certificates, static frontend files, and Nginx process state. It relocates only the shipped config's listen address, static root, and CA bundle into temporary paths. It does not contact the default API origins, use deployment credentials, require Docker, or change system configuration.

The checks cover both upstreams, API path/query/method/body forwarding, Host and TLS SNI, certificate chain and hostname validation, session and CSRF cookie path isolation, no retry or backend switching on failure, fixed read-only health probes, and blocked legacy routes.

If Nginx is not installed, build an official source release in a temporary directory and point `NGINX_TEST_BINARY` at its `objs/nginx`; installation is unnecessary. For example, on an Apple Silicon Mac with existing Homebrew OpenSSL and PCRE2 libraries:

```sh
./configure --prefix=/private/tmp/frontend-nginx-runtime --with-http_ssl_module --with-http_realip_module \
  --with-cc-opt='-I/opt/homebrew/opt/openssl@3/include -I/opt/homebrew/opt/pcre2/include' \
  --with-ld-opt='-L/opt/homebrew/opt/openssl@3/lib -L/opt/homebrew/opt/pcre2/lib'
make -j4
```

Download source releases from [nginx.org](https://nginx.org/en/download.html). For Linux, use existing OpenSSL/PCRE development libraries with `./configure --with-http_ssl_module --with-http_realip_module`; run the test binary as an unprivileged user.
