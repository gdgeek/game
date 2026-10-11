"""Exercise the shipped Nginx config against local HTTPS servers, without Docker.

Run with ``NGINX_TEST_BINARY=/path/to/nginx python3 -m unittest discover
-s dockerfile/tests -p 'test_frontend_proxy.py' -v``. No production endpoint or
credential is used. See README.md for the optional Nginx build instructions.
"""

from __future__ import annotations

import contextlib
import http.client
import http.cookiejar
import http.server
import io
import json
import os
from pathlib import Path
import re
import shutil
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import unittest
import urllib.request
import urllib.response
from http.cookies import SimpleCookie


REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "dockerfile/40-configure-frontends.sh"
TEMPLATE = REPO / "dockerfile/frontend-nginx.conf.template"


def test_environment() -> dict[str, str]:
    env = os.environ.copy()
    # Homebrew's gettext is deliberately not linked into the global PATH.
    if not shutil.which("envsubst"):
        gettext_bin = Path("/opt/homebrew/opt/gettext/bin")
        if (gettext_bin / "envsubst").is_file():
            env["PATH"] = f"{gettext_bin}{os.pathsep}{env.get('PATH', '')}"
    return env


def render(output: Path, template: Path, origins: tuple[str, str] | None = None):
    env = test_environment()
    env.pop("APP_API_1_URL", None)
    env.pop("APP_API_2_URL", None)
    env["NGINX_FRONTEND_TEMPLATE_PATH"] = str(template)
    env["NGINX_FRONTEND_OUTPUT_PATH"] = str(output)
    if origins is not None:
        env["APP_API_1_URL"], env["APP_API_2_URL"] = origins
    return subprocess.run(
        ["/bin/sh", str(SCRIPT)], env=env, text=True, capture_output=True, timeout=10
    )


class RendererTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="frontend-render-")
        self.addCleanup(self.tmp.cleanup)
        self.directory = Path(self.tmp.name)
        self.template = self.directory / "variables.template"
        self.template.write_text(
            "${APP_API_1_ORIGIN}|${APP_API_1_HOST}|${APP_API_1_TLS_NAME}\n"
            "${APP_API_2_ORIGIN}|${APP_API_2_HOST}|${APP_API_2_TLS_NAME}\n"
            "$host|${host}|$request_uri\n"
        )
        self.output = self.directory / "rendered.conf"

    def test_defaults_and_nginx_variables_are_preserved(self):
        result = render(self.output, self.template)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            self.output.read_text(),
            "https://x.b0.01xr.com|x.b0.01xr.com|x.b0.01xr.com\n"
            "https://x.b1.01xr.com|x.b1.01xr.com|x.b1.01xr.com\n"
            "$host|${host}|$request_uri\n",
        )

    def test_local_host_ports_and_one_trailing_slash(self):
        result = render(
            self.output, self.template,
            ("https://localhost:18443/", "https://127.0.0.1:65535"),
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            self.output.read_text(),
            "https://localhost:18443|localhost:18443|localhost\n"
            "https://127.0.0.1:65535|127.0.0.1:65535|127.0.0.1\n"
            "$host|${host}|$request_uri\n",
        )

    def test_invalid_origins_fail_without_writing_configuration(self):
        invalid = [
            "http://localhost", "https://user:password@localhost",
            "https://localhost/admin-api", "https://localhost?query=value",
            "https://localhost#fragment", "https://localhost//",
            "https://localhost\\path", "https://localhost:abc",
            "https://localhost:0", "https://localhost:65536",
            "https://localhost:443:443", "https://-invalid.example",
            "https://invalid..example", "https://localhost example",
            "https://localhost\ninclude /tmp/injected;", "https://localhost\r",
            "https://localhost\t", "https://localhost$host",
            "https://localhost;include", "https://[::1]",
        ]
        for backend in (0, 1):
            for origin in invalid:
                with self.subTest(backend=backend, origin=repr(origin)):
                    origins = ["https://localhost:443", "https://localhost:444"]
                    origins[backend] = origin
                    result = render(self.output, self.template, tuple(origins))
                    self.assertNotEqual(result.returncode, 0)
                    self.assertFalse(self.output.exists())


class IPv6FixtureListener(http.server.ThreadingHTTPServer):
    address_family = socket.AF_INET6
    daemon_threads = True

    def server_bind(self):
        self.socket.setsockopt(socket.IPPROTO_IPV6, socket.IPV6_V6ONLY, 1)
        super().server_bind()


class FixtureServer(http.server.ThreadingHTTPServer):
    daemon_threads = True

    def __init__(self, name: str, certificate: Path, key: Path):
        super().__init__(("127.0.0.1", 0), FixtureHandler)
        self.name = name
        self.records: list[dict] = []
        self.names: list[str | None] = []
        self.record_lock = threading.Lock()
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(str(certificate), str(key))

        def server_name(connection, name, _context):
            connection.fixture_sni = name
            with self.record_lock:
                self.names.append(name)

        tls.set_servername_callback(server_name)
        self.socket = tls.wrap_socket(self.socket, server_side=True)
        self.listeners = [self]
        # localhost can resolve to both loopback families. Bind each explicitly
        # rather than relaxing proxy retry behavior or listening on public NICs.
        if any(address[0] == socket.AF_INET6 for address in socket.getaddrinfo("localhost", 0)):
            ipv6 = IPv6FixtureListener(("::1", self.server_port), FixtureHandler)
            ipv6.name = self.name
            ipv6.records = self.records
            ipv6.names = self.names
            ipv6.record_lock = self.record_lock
            ipv6.socket = tls.wrap_socket(ipv6.socket, server_side=True)
            self.listeners.append(ipv6)
        for listener in self.listeners:
            listener.thread = threading.Thread(target=listener.serve_forever, daemon=True)
            listener.thread.start()

    @property
    def origin(self) -> str:
        return f"https://localhost:{self.server_port}"

    def clear(self):
        with self.record_lock:
            self.records.clear()
            self.names.clear()

    def close(self):
        for listener in self.listeners:
            listener.shutdown()
            listener.server_close()
            listener.thread.join(timeout=3)


class FixtureHandler(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.handle_request()

    def do_POST(self):
        self.handle_request()

    def do_PUT(self):
        self.handle_request()

    def do_HEAD(self):
        self.handle_request()

    def handle_request(self):
        body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        record = {
            "backend": self.server.name,
            "method": self.command,
            "path": self.path,
            "body": body.decode("utf-8"),
            "headers": dict(self.headers.items()),
            "sni": getattr(self.connection, "fixture_sni", None),
        }
        with self.server.record_lock:
            self.server.records.append(record)
        if self.path.startswith("/admin-api/drop"):
            self.connection.shutdown(socket.SHUT_RDWR)
            self.connection.close()
            return
        status = 401 if self.path.startswith("/admin-api/unauthorized") else 200
        payload = json.dumps(record).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        if self.path.startswith("/admin-api/session"):
            self.send_header(
                "Set-Cookie",
                f"CHECKINADMINSESSID=fixture-{self.server.name}; Path=/; "
                "Domain=localhost; Secure; HttpOnly; SameSite=Lax",
            )
            self.send_header(
                "Set-Cookie",
                f"_csrf-checkin-admin=csrf-{self.server.name}; Path=/admin-api; "
                "Domain=.localhost; Secure; SameSite=Lax",
            )
        if self.path.startswith("/health"):
            self.send_header("Set-Cookie", "must-not-leak=yes; Path=/")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(payload)

    def log_message(self, *_args):
        pass


def run_openssl(binary: str, directory: Path, *arguments: str):
    result = subprocess.run(
        [binary, *arguments], cwd=directory, text=True, capture_output=True, timeout=20
    )
    if result.returncode:
        raise RuntimeError(f"OpenSSL fixture creation failed: {result.stderr}")


@unittest.skipUnless(
    os.environ.get("NGINX_TEST_BINARY") or shutil.which("nginx"),
    "Real Nginx required: set NGINX_TEST_BINARY to a local Nginx executable",
)
class NginxProxyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.binary = os.environ.get("NGINX_TEST_BINARY") or shutil.which("nginx")
        openssl = os.environ.get("OPENSSL_TEST_BINARY") or shutil.which("openssl")
        if not openssl:
            raise unittest.SkipTest("OpenSSL CLI is required for local test certificates")
        cls.tmp = tempfile.TemporaryDirectory(prefix="frontend-https-fixtures-")
        cls.addClassCleanup(cls.tmp.cleanup)
        directory = Path(cls.tmp.name)
        cls.ca = directory / "ca.crt"
        run_openssl(
            openssl, directory, "req", "-x509", "-newkey", "rsa:2048",
            "-nodes", "-days", "1", "-subj", "/CN=Frontend Proxy Test CA",
            "-addext", "basicConstraints=critical,CA:TRUE",
            "-keyout", "ca.key", "-out", "ca.crt",
        )
        for name, hostname in (("valid", "localhost"), ("wrong-name", "wrong.example")):
            run_openssl(
                openssl, directory, "req", "-new", "-newkey", "rsa:2048",
                "-nodes", "-subj", f"/CN={hostname}", "-keyout", f"{name}.key",
                "-out", f"{name}.csr",
            )
            (directory / f"{name}.ext").write_text(
                f"subjectAltName=DNS:{hostname}\nextendedKeyUsage=serverAuth\n"
                "basicConstraints=CA:FALSE\n"
            )
            run_openssl(
                openssl, directory, "x509", "-req", "-in", f"{name}.csr",
                "-CA", "ca.crt", "-CAkey", "ca.key", "-CAcreateserial",
                "-days", "1", "-sha256", "-extfile", f"{name}.ext",
                "-out", f"{name}.crt",
            )
        run_openssl(
            openssl, directory, "req", "-x509", "-newkey", "rsa:2048", "-nodes",
            "-days", "1", "-subj", "/CN=localhost",
            "-addext", "subjectAltName=DNS:localhost",
            "-keyout", "untrusted.key", "-out", "untrusted.crt",
        )
        cls.b0 = FixtureServer("b0", directory / "valid.crt", directory / "valid.key")
        cls.addClassCleanup(cls.b0.close)
        cls.b1 = FixtureServer("b1", directory / "valid.crt", directory / "valid.key")
        cls.addClassCleanup(cls.b1.close)
        cls.untrusted = FixtureServer(
            "untrusted", directory / "untrusted.crt", directory / "untrusted.key"
        )
        cls.addClassCleanup(cls.untrusted.close)
        cls.wrong_name = FixtureServer(
            "wrong-name", directory / "wrong-name.crt", directory / "wrong-name.key"
        )
        cls.addClassCleanup(cls.wrong_name.close)

    def setUp(self):
        for server in (self.b0, self.b1, self.untrusted, self.wrong_name):
            server.clear()

    @contextlib.contextmanager
    def nginx(self, origins: tuple[str, str] | None = None):
        with tempfile.TemporaryDirectory(prefix="frontend-nginx-") as temporary:
            directory = Path(temporary)
            (directory / "logs").mkdir()
            root = directory / "html"
            root.mkdir()
            (root / "index.html").write_text("<title>local frontend fixture</title>")
            (root / "asset.js").write_text("window.frontendFixture = true;")
            for blocked_file in ("index.php", ".env", "source.sql", "source.map"):
                (root / blocked_file).write_text("must not be served by Nginx")
            rendered = directory / "server.conf"
            result = render(rendered, TEMPLATE, origins or (self.b0.origin, self.b1.origin))
            self.assertEqual(result.returncode, 0, result.stderr)
            with socket.socket() as reservation:
                reservation.bind(("127.0.0.1", 0))
                port = reservation.getsockname()[1]
            server = rendered.read_text()
            # Only relocate container resources. Proxy rules, TLS validation and
            # headers are the actual shipped configuration, unmodified.
            server = server.replace("/usr/share/nginx/html", str(root))
            server = server.replace("/etc/ssl/certs/ca-certificates.crt", str(self.ca))
            server, count = re.subn(r"\blisten\s+80\s*;", f"listen 127.0.0.1:{port};", server)
            self.assertEqual(count, 1, "Expected one container HTTP listen directive")
            rendered.write_text(server)
            config = directory / "nginx.conf"
            self.error_log = directory / "error.log"
            config.write_text(
                "daemon off;\nmaster_process off;\nworker_processes 1;\n"
                f"pid {directory}/nginx.pid;\nerror_log {directory}/error.log info;\n"
                "events { worker_connections 128; }\n"
                "http {\naccess_log off;\ndefault_type application/octet-stream;\n"
                f"client_body_temp_path {directory}/client_temp;\n"
                f"proxy_temp_path {directory}/proxy_temp;\ninclude {rendered};\n}}\n"
            )
            command = [str(self.binary), "-p", str(directory), "-c", str(config)]
            syntax = subprocess.run(command + ["-t"], text=True, capture_output=True, timeout=10)
            self.assertEqual(syntax.returncode, 0, syntax.stderr)
            with (directory / "process.log").open("w+") as process_log:
                process = subprocess.Popen(command, stdout=process_log, stderr=process_log)
                try:
                    deadline = time.monotonic() + 5
                    while time.monotonic() < deadline:
                        if process.poll() is not None:
                            process_log.seek(0)
                            self.fail(f"Nginx exited early: {process_log.read()}")
                        try:
                            with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                                break
                        except OSError:
                            time.sleep(0.025)
                    else:
                        self.fail("Local Nginx did not start within five seconds")
                    yield port
                finally:
                    process.terminate()
                    try:
                        process.wait(timeout=3)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=3)

    def request(self, port: int, path: str, method="GET", body=None, headers=None):
        connection = http.client.HTTPConnection("127.0.0.1", port, timeout=8)
        try:
            request_headers = {"Host": "console.test"}
            request_headers.update(headers or {})
            connection.request(method, path, body=body, headers=request_headers)
            response = connection.getresponse()
            return response.status, response.headers, response.read()
        finally:
            connection.close()

    def test_static_frontend_and_healthz_do_not_call_an_api(self):
        with self.nginx() as port:
            status, _, body = self.request(port, "/")
            self.assertEqual(status, 200)
            self.assertIn(b"local frontend fixture", body)
            self.assertEqual(self.request(port, "/asset.js")[0], 200)
            status, _, body = self.request(port, "/healthz")
            self.assertEqual(status, 200)
            self.assertEqual(body.strip(), b"ok")
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])

    def test_old_and_unselected_routes_are_not_proxied(self):
        paths = [
            "/admin-api/session", "/v2/checkin", "/api/swagger",
            "/backends/b2/admin-api/session", "/backends/b0/v2/checkin",
            "/backends/b0/health/", "/backends/b0/admin-api/../../v2/checkin",
            "/index.php", "/.env", "/source.sql", "/source.map",
        ]
        with self.nginx() as port:
            for path in paths:
                with self.subTest(path=path):
                    self.assertEqual(self.request(port, path)[0], 404)
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])

    def test_each_prefix_preserves_admin_path_query_post_body_host_and_sni(self):
        with self.nginx() as port:
            for server in (self.b0, self.b1):
                with self.subTest(backend=server.name):
                    path = f"/backends/{server.name}/admin-api/login?next=a%2Fb&x=1&x=2"
                    body = '{"username":"fixture-only","password":"not-a-real-secret"}'
                    status, _, payload = self.request(
                        port, path, "POST", body,
                        {
                            "Content-Type": "application/json", "X-CSRF-Token": "fixture-csrf",
                            "X-Forwarded-For": "203.0.113.99", "X-Real-IP": "203.0.113.98",
                            "X-Forwarded-Proto": "http", "X-Forwarded-Host": "forged.example",
                        },
                    )
                    self.assertEqual(status, 200, self.error_log.read_text())
                    upstream = json.loads(payload)
                    self.assertEqual(upstream["backend"], server.name)
                    self.assertEqual(upstream["path"], "/admin-api/login?next=a%2Fb&x=1&x=2")
                    self.assertEqual(upstream["method"], "POST")
                    self.assertEqual(upstream["body"], body)
                    self.assertEqual(upstream["headers"]["Host"], f"localhost:{server.server_port}")
                    self.assertEqual(upstream["headers"]["X-CSRF-Token"], "fixture-csrf")
                    self.assertEqual(upstream["headers"]["X-Forwarded-For"], "127.0.0.1")
                    self.assertEqual(upstream["headers"]["X-Real-IP"], "127.0.0.1")
                    self.assertEqual(upstream["headers"]["X-Forwarded-Proto"], "https")
                    self.assertEqual(upstream["headers"]["X-Forwarded-Host"], "console.test")
                    self.assertEqual(upstream["sni"], "localhost")
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(len(self.b1.records), 1)

    def test_put_device_assignment_reaches_only_the_selected_backend(self):
        with self.nginx() as port:
            status, _, payload = self.request(
                port, "/backends/b1/admin-api/devices/42/game", "PUT", '{"gameId":7}',
                {"Content-Type": "application/json"},
            )
            self.assertEqual(status, 200)
            upstream = json.loads(payload)
            self.assertEqual(upstream["method"], "PUT")
            self.assertEqual(upstream["path"], "/admin-api/devices/42/game")
            self.assertEqual(upstream["body"], '{"gameId":7}')
        self.assertEqual(self.b0.records, [])
        self.assertEqual(len(self.b1.records), 1)

    def test_identical_session_and_csrf_cookies_remain_isolated_by_backend(self):
        jar = http.cookiejar.CookieJar()
        with self.nginx() as port:
            for backend in ("b0", "b1"):
                path = f"/backends/{backend}/admin-api/session"
                status, headers, payload = self.request(port, path)
                self.assertEqual(status, 200)
                cookies = headers.get_all("Set-Cookie")
                self.assertEqual(len(cookies), 2)
                for value in cookies:
                    parsed = SimpleCookie()
                    parsed.load(value)
                    self.assertEqual(len(parsed), 1)
                    cookie = next(iter(parsed.values()))
                    self.assertEqual(cookie["path"], f"/backends/{backend}/")
                    self.assertEqual(cookie["domain"].lstrip("."), "console.test")
                    self.assertTrue(cookie["secure"])
                    self.assertTrue(cookie["httponly"])
                    self.assertEqual(cookie["samesite"].lower(), "lax")
                url = f"https://console.test{path}"
                browser_request = urllib.request.Request(url)
                browser_response = urllib.response.addinfourl(io.BytesIO(payload), headers, url, status)
                jar.extract_cookies(browser_response, browser_request)
            for server in (self.b0, self.b1):
                path = f"/backends/{server.name}/admin-api/devices"
                browser_request = urllib.request.Request(f"https://console.test{path}")
                jar.add_cookie_header(browser_request)
                cookie_header = browser_request.get_header("Cookie")
                self.assertIn(f"CHECKINADMINSESSID=fixture-{server.name}", cookie_header)
                other = "b1" if server.name == "b0" else "b0"
                self.assertNotIn(f"fixture-{other}", cookie_header)
                self.assertNotIn(f"csrf-{other}", cookie_header)
                status, _, payload = self.request(port, path, headers={"Cookie": cookie_header})
                self.assertEqual(status, 200)
                self.assertEqual(json.loads(payload)["headers"]["Cookie"], cookie_header)
        root_request = urllib.request.Request("https://console.test/")
        jar.add_cookie_header(root_request)
        self.assertIsNone(root_request.get_header("Cookie"))

    def test_upstream_transport_failure_is_not_retried_or_sent_to_other_backend(self):
        with self.nginx() as port:
            status, _, _ = self.request(port, "/backends/b0/admin-api/drop", "POST", "fixture")
            self.assertEqual(status, 502)
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(self.b1.records, [])

    def test_upstream_auth_failure_is_returned_without_switching_backends(self):
        with self.nginx() as port:
            status, _, payload = self.request(port, "/backends/b0/admin-api/unauthorized")
            self.assertEqual(status, 401)
            self.assertEqual(json.loads(payload)["backend"], "b0")
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(self.b1.records, [])

    def test_tls_rejects_untrusted_certificates_and_wrong_hostnames(self):
        for invalid in (self.untrusted, self.wrong_name):
            with self.subTest(certificate=invalid.name):
                with self.nginx((invalid.origin, self.b1.origin)) as port:
                    self.assertEqual(self.request(port, "/backends/b0/admin-api/session")[0], 502)
                self.assertEqual(invalid.records, [])
                self.assertIn("localhost", invalid.names)
                self.assertEqual(self.b1.records, [])

    def test_health_is_a_fixed_get_without_credentials_body_or_cookies(self):
        with self.nginx() as port:
            for server in (self.b0, self.b1):
                with self.subTest(backend=server.name):
                    status, headers, payload = self.request(
                        port, f"/backends/{server.name}/health?write=yes", "GET", "discard-this",
                        {
                            "Cookie": "CHECKINADMINSESSID=fixture", "Authorization": "Bearer fixture",
                            "X-Forwarded-For": "203.0.113.99", "X-Real-IP": "203.0.113.98",
                            "X-Forwarded-Proto": "http", "X-Forwarded-Host": "forged.example",
                        },
                    )
                    self.assertEqual(status, 200)
                    self.assertIsNone(headers.get("Set-Cookie"))
                    upstream = json.loads(payload)
                    self.assertEqual(upstream["method"], "GET")
                    self.assertEqual(upstream["path"], "/health")
                    self.assertEqual(upstream["body"], "")
                    normalized = {key.lower(): value for key, value in upstream["headers"].items()}
                    self.assertNotIn("cookie", normalized)
                    self.assertNotIn("authorization", normalized)
                    self.assertIn(normalized.get("content-length", "0"), ("", "0"))
                    self.assertEqual(normalized["x-forwarded-for"], "127.0.0.1")
                    self.assertEqual(normalized["x-real-ip"], "127.0.0.1")
                    self.assertEqual(normalized["x-forwarded-proto"], "https")
                    self.assertEqual(normalized["x-forwarded-host"], "console.test")
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(len(self.b1.records), 1)

    def test_health_rejects_mutations_without_contacting_an_upstream(self):
        with self.nginx() as port:
            for backend in ("b0", "b1"):
                for method in ("POST", "PUT", "DELETE", "PATCH"):
                    with self.subTest(backend=backend, method=method):
                        status, _, _ = self.request(port, f"/backends/{backend}/health", method, "fixture")
                        self.assertIn(status, (403, 405))
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])

    def test_health_head_uses_a_read_only_get_and_returns_no_body(self):
        with self.nginx() as port:
            for server in (self.b0, self.b1):
                with self.subTest(backend=server.name):
                    status, headers, body = self.request(
                        port, f"/backends/{server.name}/health?write=yes", "HEAD", "discard-this",
                        {"Cookie": "fixture", "Authorization": "Bearer fixture"},
                    )
                    self.assertEqual(status, 200)
                    self.assertEqual(body, b"")
                    self.assertIsNone(headers.get("Set-Cookie"))
                    self.assertEqual(len(server.records), 1)
                    upstream = server.records[0]
                    self.assertEqual(upstream["method"], "GET")
                    self.assertEqual(upstream["path"], "/health")
                    self.assertEqual(upstream["body"], "")
                    normalized = {key.lower(): value for key, value in upstream["headers"].items()}
                    self.assertNotIn("cookie", normalized)
                    self.assertNotIn("authorization", normalized)


if __name__ == "__main__":
    unittest.main(verbosity=2)
