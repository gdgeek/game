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
import struct
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


def render(
    output: Path, template: Path,
    origins: tuple[str, ...] | dict[int | str, str] | None = None,
    runtime_config: Path | None = None, settings: dict[str, str] | None = None,
):
    env = test_environment()
    for key in list(env):
        if re.fullmatch(r"APP_API_[0-9]+_URL", key):
            env.pop(key)
    env["NGINX_FRONTEND_TEMPLATE_PATH"] = str(template)
    env["NGINX_FRONTEND_OUTPUT_PATH"] = str(output)
    env["NGINX_FRONTEND_RUNTIME_CONFIG_PATH"] = str(runtime_config or output.parent / "runtime-config.json")
    env.pop("APP_API_UPSTREAM_HOST", None)
    env.pop("APP_API_RESOLVER", None)
    for key in list(env):
        if re.fullmatch(r"APP_API_[0-9]+_WEIGHT", key):
            env.pop(key)
    env.update(settings or {})
    if origins is not None:
        configured = origins.items() if isinstance(origins, dict) else enumerate(origins, 1)
        for slot, origin in configured:
            env[f"APP_API_{slot}_URL"] = origin
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
            "before\n# FRONTEND_HTTP_CONFIG\n# FRONTEND_BACKEND_ROUTES\n# FRONTEND_RUNTIME_CONFIG\n"
            "$host|${host}|$request_uri\nafter\n"
        )
        self.output = self.directory / "rendered.conf"

    def manifest(self):
        return json.loads((self.directory / "runtime-config.json").read_text())

    def test_at_least_one_backend_is_required_without_image_defaults(self):
        result = render(self.output, self.template)
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse(self.output.exists())
        dockerfile = (REPO / "dockerfile/FrontendDockerfile").read_text()
        self.assertNotRegex(dockerfile, r"APP_API_[0-9]+_URL=")

    def test_one_three_and_sparse_backends_generate_only_stable_sorted_routes(self):
        for slots in ((1,), (3, 1, 2), (10, 1, 3), (9007199254740991,)):
            with self.subTest(slots=slots):
                origins = {slot: f"https://api{slot}.example" for slot in slots}
                result = render(self.output, self.template, origins)
                self.assertEqual(result.returncode, 0, result.stderr)
                config = self.output.read_text()
                expected = [f"b{slot - 1}" for slot in sorted(slots)]
                expected_api = expected if "b0" in expected else expected + ["b0"]
                self.assertEqual(
                    re.findall(r"location \^~ /backends/(b[0-9]+)/admin-api/", config),
                    expected,
                )
                self.assertEqual(
                    re.findall(r"location = /backends/(b[0-9]+)/health", config),
                    expected,
                )
                self.assertEqual(
                    re.findall(r"location \^~ /api/backends/(b[0-9]+)/admin-api/", config), expected_api,
                )
                self.assertEqual(
                    re.findall(r"location = /api/backends/(b[0-9]+)/health", config), expected,
                )
                self.assertEqual(self.manifest(), {"apiBase": "/api/admin-api", "automatic": True, "auth": "jwt"})
                self.assertNotIn("# FRONTEND_BACKEND_ROUTES", config)
                self.assertNotIn("# FRONTEND_RUNTIME_CONFIG", config)
                self.assertIn("before\n", config)
                self.assertIn("$host|${host}|$request_uri\nafter\n", config)

    def test_manifest_is_a_separate_file_for_more_than_one_hundred_backends(self):
        origins = {slot: f"https://api{slot}.example" for slot in range(1, 6)}
        result = render(self.output, self.template, origins)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.manifest(), {"apiBase": "/api/admin-api", "automatic": True, "auth": "jwt"})
        self.assertNotIn('"backends":', self.output.read_text())

    def test_weighted_pools_default_to_one_and_accept_valid_overrides(self):
        result = render(self.output, self.template, {1: "https://api1.example", 3: "https://api3.example"},
                        settings={"APP_API_1_WEIGHT": "3", "APP_API_3_WEIGHT": "1"})
        self.assertEqual(result.returncode, 0, result.stderr)
        config = self.output.read_text()
        self.assertIn('split_clients "$request_id" $api_pool', config)
        self.assertIn("75.00% api_pool_b0;", config)
        self.assertIn("* api_pool_b2;", config)
        self.assertIn("upstream api_pool_b0", config)
        self.assertIn("server api1.example:443 resolve max_fails=1 fail_timeout=10s;", config)
        self.assertIn("server api3.example:443 resolve max_fails=1 fail_timeout=10s backup;", config)
        self.assertIn("upstream api_pool_b2", config)
        self.assertIn("server api3.example:443 resolve max_fails=1 fail_timeout=10s;", config)
        self.assertEqual(self.manifest(), {"apiBase": "/api/admin-api", "automatic": True, "auth": "jwt"})

    def test_invalid_weights_and_orphan_weights_fail_before_writing(self):
        for settings in (
            {"APP_API_1_WEIGHT": "0"}, {"APP_API_1_WEIGHT": "01"},
            {"APP_API_1_WEIGHT": "-1"}, {"APP_API_1_WEIGHT": "1.5"},
            {"APP_API_1_WEIGHT": "1; include /tmp/x;"},
            {"APP_API_2_WEIGHT": "2"},
            {"APP_API_1_WEIGHT": "1", "APP_API_2_WEIGHT": "10000"},
        ):
            with self.subTest(settings=settings):
                self.output.unlink(missing_ok=True)
                result = render(self.output, self.template, {1: "https://api1.example"}, settings=settings)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.output.exists())

    def test_common_upstream_host_and_resolver_are_validated(self):
        for settings in (
            {"APP_API_UPSTREAM_HOST": "shared.example/path"},
            {"APP_API_UPSTREAM_HOST": "shared.example;return 444"},
            {"APP_API_RESOLVER": "localhost"},
            {"APP_API_RESOLVER": "127.0.0.1:0"},
            {"APP_API_RESOLVER": "127.0.0.1;include"},
        ):
            with self.subTest(settings=settings):
                self.output.unlink(missing_ok=True)
                result = render(self.output, self.template, {1: "https://api1.example"}, settings=settings)
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.output.exists())

    def test_local_host_ports_and_one_trailing_slash(self):
        result = render(
            self.output, self.template,
            ("https://localhost:18443/", "https://127.0.0.1:65535", "https://api.example:443/"),
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        config = self.output.read_text()
        self.assertIn("proxy_pass $api_upstream_url$api_admin_target;", config)
        self.assertIn("proxy_set_header Host $api_upstream_host;", config)
        self.assertIn("proxy_ssl_name $api_upstream_tls_name;", config)
        self.assertIn("map $api_pool $api_upstream_host", config)
        self.assertEqual(self.manifest(), {"apiBase": "/api/admin-api", "automatic": True, "auth": "jwt"})

    def test_manifest_normalizes_default_and_non_default_port_numbers(self):
        result = render(
            self.output, self.template,
            ("https://api.example:00443", "https://other.example:08080", "https://third.example:00080"),
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        config = self.output.read_text()
        self.assertIn("proxy_pass https://api.example/admin-api/;", config)
        self.assertIn("proxy_pass https://other.example:8080/admin-api/;", config)
        self.assertIn("proxy_pass https://third.example:80/admin-api/;", config)

    def test_set_but_empty_origins_fail_in_any_slot(self):
        for slot in (1, 2, 3, 10):
            with self.subTest(slot=slot):
                result = render(self.output, self.template, {1: "https://api.example", slot: ""})
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.output.exists())

    def test_invalid_numeric_slots_fail_instead_of_aliasing_other_routes(self):
        for slot in ("0", "01", "9007199254740992", "999999999999999999999999999999999999"):
            with self.subTest(slot=slot):
                result = render(self.output, self.template, {1: "https://api.example", slot: "https://other.example"})
                self.assertNotEqual(result.returncode, 0)
                self.assertFalse(self.output.exists())

    def test_invalid_origins_fail_without_writing_configuration(self):
        invalid = [
            "http://localhost", "https://user:password@localhost",
            "https://localhost/admin-api", "https://localhost?query=value",
            "https://localhost#fragment", "https://localhost//",
            "https://localhost\\path", "https://localhost:abc",
            "https://localhost:0", "https://localhost:65536",
            "https://localhost:443:443", "https://-invalid.example",
            "https://invalid..example", "https://localhost example",
            "https://localhost\ninclude /tmp/injected;", "https://localhost\n", "https://localhost\r",
            "https://localhost\t", "https://localhost$host",
            "https://localhost;include", "https://[::1]",
            "https://" + "a" * 64 + ".example",
            "https://" + ".".join(["a" * 63] * 4),
        ]
        for backend in (0, 1, 2):
            for origin in invalid:
                with self.subTest(backend=backend, origin=repr(origin)):
                    self.output.unlink(missing_ok=True)
                    origins = ["https://localhost:443", "https://localhost:444", "https://localhost:445"]
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
        self.drop_all = False
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
            ipv6.controller = self
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
        self.drop_all = False

    def close(self):
        for listener in self.listeners:
            listener.shutdown()
            listener.server_close()
            listener.thread.join(timeout=3)


class LocalDNS:
    """Resolve only localhost A records to loopback; never forward DNS traffic."""
    def __init__(self):
        self.socket = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
        self.socket.bind(("127.0.0.1", 0))
        self.socket.settimeout(0.1)
        self.port = self.socket.getsockname()[1]
        self.queries = []
        self.running = True
        self.thread = threading.Thread(target=self.serve, daemon=True)
        self.thread.start()

    def serve(self):
        while self.running:
            try:
                query, peer = self.socket.recvfrom(4096)
            except socket.timeout:
                continue
            except OSError:
                return
            end, labels = 12, []
            while query[end]:
                length = query[end]
                labels.append(query[end + 1:end + 1 + length].decode("ascii"))
                end += length + 1
            name = ".".join(labels)
            qtype = struct.unpack("!H", query[end + 1:end + 3])[0]
            self.queries.append(name)
            question = query[12:end + 5]
            permitted = name == "localhost" and qtype == 1
            header = query[:2] + struct.pack("!HHHHH", 0x8180, 1, int(permitted), 0, 0)
            answer = (b"\xc0\x0c" + struct.pack("!HHIH", 1, 1, 1, 4) +
                      socket.inet_aton("127.0.0.1")) if permitted else b""
            self.socket.sendto(header + question + answer, peer)

    def close(self):
        self.running = False
        self.thread.join(timeout=2)
        self.socket.close()


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
        controller = getattr(self.server, "controller", self.server)
        record = {
            "backend": controller.name,
            "method": self.command,
            "path": self.path,
            "body": body.decode("utf-8"),
            "headers": dict(self.headers.items()),
            "sni": getattr(self.connection, "fixture_sni", None),
        }
        with controller.record_lock:
            controller.records.append(record)
        if self.path.startswith("/admin-api/drop") or getattr(controller, "drop_all", False):
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
        cls.b2 = FixtureServer("b2", directory / "valid.crt", directory / "valid.key")
        cls.addClassCleanup(cls.b2.close)
        cls.untrusted = FixtureServer(
            "untrusted", directory / "untrusted.crt", directory / "untrusted.key"
        )
        cls.addClassCleanup(cls.untrusted.close)
        cls.wrong_name = FixtureServer(
            "wrong-name", directory / "wrong-name.crt", directory / "wrong-name.key"
        )
        cls.addClassCleanup(cls.wrong_name.close)

    def setUp(self):
        for server in (self.b0, self.b1, self.b2, self.untrusted, self.wrong_name):
            server.clear()

    @contextlib.contextmanager
    def nginx(self, origins: tuple[str, ...] | dict[int, str] | None = None, settings=None):
        dns = LocalDNS()
        try:
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
                render_settings = {"APP_API_RESOLVER": f"127.0.0.1:{dns.port}", **(settings or {})}
                result = render(
                    rendered, TEMPLATE,
                    origins if origins is not None else (self.b0.origin, self.b1.origin, self.b2.origin),
                    runtime_config=root / "runtime-config.json", settings=render_settings,
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                with socket.socket() as reservation:
                    reservation.bind(("127.0.0.1", 0))
                    port = reservation.getsockname()[1]
                server = rendered.read_text()
                self.assertTrue(
                    all(authority.startswith("localhost:") for authority in
                        re.findall(r"proxy_pass https://([^/]+)/", server)),
                    "Integration fixtures must never start with non-local upstreams",
                )
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
                        dns_deadline = time.monotonic() + 5
                        while not dns.queries and time.monotonic() < dns_deadline:
                            time.sleep(0.02)
                        self.assertIn("localhost", dns.queries, "Nginx must resolve fixture upstreams through local DNS")
                        time.sleep(0.05)
                        yield port
                    finally:
                        process.terminate()
                        try:
                            process.wait(timeout=3)
                        except subprocess.TimeoutExpired:
                            process.kill()
                            process.wait(timeout=3)
        finally:
            dns.close()
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
            status, headers, body = self.request(port, "/")
            self.assertEqual(status, 200)
            self.assertEqual(headers.get("Cache-Control"), "no-cache")
            self.assertEqual(self.request(port, "/index.html")[1].get("Cache-Control"), "no-cache")
            self.assertIn(b"local frontend fixture", body)
            self.assertEqual(self.request(port, "/asset.js")[0], 200)
            status, _, body = self.request(port, "/healthz")
            self.assertEqual(status, 200)
            self.assertEqual(body.strip(), b"ok")
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])
        self.assertEqual(self.b2.records, [])

    def test_runtime_manifest_is_public_no_store_and_never_calls_an_api(self):
        with self.nginx() as port:
            for conditional in ({}, {"If-None-Match": '"stale-config"'}, {"If-Modified-Since": "Thu, 01 Jan 2099 00:00:00 GMT"}):
                status, headers, payload = self.request(
                    port, "/runtime-config.json", headers={
                        "Cookie": "fixture-session", "Authorization": "Bearer fixture",
                        **conditional,
                    },
                )
                self.assertEqual(status, 200)
                self.assertEqual(headers.get_content_type(), "application/json")
                self.assertEqual(headers.get("Cache-Control"), "no-store")
                self.assertIsNone(headers.get("ETag"))
                self.assertIsNone(headers.get("Last-Modified"))
                self.assertIsNone(headers.get("Set-Cookie"))
                self.assertEqual(json.loads(payload), {"apiBase": "/api/admin-api", "automatic": True, "auth": "jwt"})
        for server in (self.b0, self.b1, self.b2):
            self.assertEqual(server.records, [])

    def test_multiple_backends_load_in_nginx_and_serve_the_automatic_manifest(self):
        origins = {slot: self.b0.origin for slot in range(1, 6)}
        with self.nginx(origins) as port:
            status, headers, payload = self.request(port, "/runtime-config.json")
            self.assertEqual(status, 200)
            self.assertEqual(headers.get("Cache-Control"), "no-store")
            self.assertEqual(json.loads(payload), {"apiBase": "/api/admin-api", "automatic": True, "auth": "jwt"})
            self.assertEqual(self.request(port, "/backends/b5/health")[0], 404)
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])
        self.assertEqual(self.b2.records, [])

    def test_single_backend_and_sparse_slots_never_create_unconfigured_routes(self):
        for origins, configured, missing in (
            ({1: self.b0.origin}, "b0", "b1"),
            ({3: self.b2.origin, 1: self.b0.origin}, "b2", "b1"),
        ):
            with self.subTest(slots=list(origins)):
                with self.nginx(origins) as port:
                    status, _, payload = self.request(port, f"/backends/{configured}/admin-api/session")
                    self.assertEqual(status, 200)
                    self.assertEqual(json.loads(payload)["backend"], configured)
                    for path in (f"/backends/{missing}/admin-api/session", f"/backends/{missing}/health"):
                        self.assertEqual(self.request(port, path)[0], 404)
        self.assertEqual(self.b1.records, [])

    def test_old_and_unselected_routes_are_not_proxied(self):
        paths = [
            "/admin-api/session", "/v2/checkin", "/api/swagger",
            "/backends/b3/admin-api/session", "/backends/b99/health",
            "/backends/b3/admin-api/session?url=https://localhost", "/backends/b0/v2/checkin",
            "/backends/b0/health/", "/backends/b0/admin-api/../../v2/checkin",
            "/index.php", "/.env", "/source.sql", "/source.map",
        ]
        with self.nginx() as port:
            for path in paths:
                with self.subTest(path=path):
                    self.assertEqual(self.request(port, path)[0], 404)
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])
        self.assertEqual(self.b2.records, [])

    def test_each_prefix_preserves_admin_path_query_post_body_host_and_sni(self):
        with self.nginx() as port:
            for server in (self.b0, self.b1, self.b2):
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
        self.assertEqual(len(self.b2.records), 1)

    def test_api_session_aliases_preserve_fixed_backend_and_isolate_cookie_paths(self):
        routes = [(f"/api/backends/{server.name}/admin-api/", server,
                   f"/api/backends/{server.name}/") for server in (self.b0, self.b1, self.b2)]
        routes += [("/backends/b0/admin-api/", self.b0, "/backends/b0/")]
        with self.nginx() as port:
            for prefix, server, cookie_path in routes:
                with self.subTest(prefix=prefix):
                    path = prefix + "session?next=a%2Fb&x=1&x=2"
                    status, headers, payload = self.request(port, path, "POST", "fixture-body",
                                                           {"Cookie": "fixture-session=1"})
                    self.assertEqual(status, 200)
                    record = json.loads(payload)
                    self.assertEqual(record["backend"], server.name)
                    self.assertEqual(record["path"], "/admin-api/session?next=a%2Fb&x=1&x=2")
                    self.assertEqual(record["body"], "fixture-body")
                    self.assertEqual(record["headers"]["Cookie"], "fixture-session=1")
                    self.assertEqual(record["headers"]["Host"], f"localhost:{server.server_port}")
                    self.assertEqual(record["sni"], "localhost")
                    self.assertEqual(len(headers.get_all("Set-Cookie")), 2)
                    for value in headers.get_all("Set-Cookie"):
                        parsed = SimpleCookie()
                        parsed.load(value)
                        self.assertEqual(next(iter(parsed.values()))["path"], cookie_path)
        with self.nginx((self.b0.origin, self.b1.origin)) as port:
            path = "/api/admin-api/session?next=a%2Fb&x=1&x=2"
            status, headers, payload = self.request(port, path, "POST", "fixture-body",
                                                   {"Cookie": "fixture-session=1"})
            self.assertEqual(status, 200)
            record = json.loads(payload)
            self.assertIn(record["backend"], {"b0", "b1"})
            self.assertEqual(record["path"], "/admin-api/session?next=a%2Fb&x=1&x=2")
            self.assertEqual(record["body"], "fixture-body")
            self.assertNotIn("Cookie", record["headers"])
            self.assertNotIn("Authorization", record["headers"])
            self.assertEqual(record["headers"]["Host"], f"localhost:{self.b0.server_port}")
            self.assertEqual(record["sni"], "localhost")
            self.assertIsNone(headers.get("Set-Cookie"))

    def test_stable_admin_route_uses_request_id_weight_and_shared_host(self):
        origins = (self.b0.origin, self.b1.origin)
        with self.nginx(origins, {"APP_API_1_WEIGHT": "3", "APP_API_2_WEIGHT": "1"}) as port:
            counts = {"b0": 0, "b1": 0}
            for index in range(300):
                status, response_headers, payload = self.request(
                    port, f"/api/admin-api/session?sample={index}&next=a%2Fb&x=2",
                    headers={"Cookie": "fixture-session=1", "Authorization": "Bearer fixture-access", "X-CSRF-Token": "obsolete"},
                )
                self.assertEqual(status, 200, self.error_log.read_text())
                record = json.loads(payload)
                counts[record["backend"]] += 1
                self.assertEqual(record["path"], f"/admin-api/session?sample={index}&next=a%2Fb&x=2")
                self.assertEqual(record["headers"]["Host"], f"localhost:{self.b0.server_port}")
                self.assertEqual(record["sni"], "localhost")
                self.assertEqual(record["headers"]["Authorization"], "Bearer fixture-access")
                self.assertNotIn("Cookie", record["headers"])
                self.assertNotIn("X-CSRF-Token", record["headers"])
                self.assertIsNone(response_headers.get("Set-Cookie"))
            self.assertGreater(counts["b0"], 180, counts)
            self.assertLess(counts["b0"], 270, counts)
            self.assertGreater(counts["b1"], 0, counts)

    def test_read_failover_retries_but_mutation_and_method_override_do_not_replay(self):
        self.b0.drop_all = True
        origins = (self.b0.origin, self.b1.origin)
        with self.nginx(origins) as port:
            # The selected b0 bucket records one failed attempt and returns from b1.
            for _ in range(60):
                status, _, payload = self.request(port, "/api/admin-api/session")
                self.assertEqual(status, 200, self.error_log.read_text())
                self.assertIn(json.loads(payload)["backend"], {"b0", "b1"})
                if self.b0.records and self.b1.records:
                    break
            self.assertTrue(self.b0.records, "Weighted traffic must select b0")
            self.assertTrue(self.b1.records, "Safe read failure must use the backup")

        # A new Nginx instance has a fresh passive state; every POST and
        # method-overridden GET must generate exactly one upstream request.
        self.b0.clear()
        self.b1.clear()
        self.b0.drop_all = True
        with self.nginx(origins) as port:
            attempts = 40
            for index in range(attempts):
                self.request(port, "/api/admin-api/login", "POST", f"body-{index}")
            self.assertEqual(len(self.b0.records) + len(self.b1.records), attempts)
            self.b0.clear()
            self.b1.clear()
            for _ in range(attempts):
                self.request(port, "/api/admin-api/devices", "GET", headers={
                    "X-Http-Method-Override": "DELETE",
                })
            self.assertEqual(len(self.b0.records) + len(self.b1.records), attempts)

    def test_api_session_aliases_never_retry_and_encoded_admin_names_stay_fixed(self):
        for path in ("/api/admin-api/drop", "/api/%61dmin-api/drop",
                     "/api/backends/b0/admin-api/drop"):
            self.b0.clear()
            with self.nginx((self.b0.origin,)) as port:
                self.assertEqual(self.request(port, path, "POST", "fixture")[0], 502)
                self.assertIn(self.request(port, "/api/admin-api")[0], (301, 404))
            self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(self.b1.records, [])
        self.assertEqual(self.b2.records, [])

    def test_api_session_without_b0_and_unknown_backend_aliases_are_rejected(self):
        with self.nginx({3: self.b2.origin}) as port:
            for path in ("/api/admin-api", "/api/backends/b0/admin-api/session",
                         "/api/backends/b0/health"):
                self.assertEqual(self.request(port, path)[0], 404)
            for path in ("/api/admin-api/session", "/api/%61dmin-api/session"):
                status, _, body = self.request(port, path)
                self.assertEqual(status, 200)
                self.assertEqual(json.loads(body)["backend"], "b2")
            status, _, body = self.request(port, "/api/backends/b2/admin-api/session")
            self.assertEqual(status, 200)
            self.assertEqual(json.loads(body)["backend"], "b2")
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])
        self.assertEqual(len(self.b2.records), 3)

    def test_api_backend_health_aliases_are_fixed_credential_free_reads(self):
        with self.nginx() as port:
            for server in (self.b0, self.b1, self.b2):
                for method in ("GET", "HEAD"):
                    status, headers, payload = self.request(
                        port, f"/api/backends/{server.name}/health?write=1", method, "discard",
                        {"Authorization": "Bearer discard", "Cookie": "discard=1",
                         "X-Http-Method-Override": "DELETE"},
                    )
                    self.assertEqual(status, 200)
                    self.assertIsNone(headers.get("Set-Cookie"))
                    record = server.records[-1]
                    self.assertEqual(record["method"], "GET")
                    self.assertEqual(record["path"], "/health")
                    self.assertEqual(record["body"], "")
                    for header in ("Cookie", "Authorization", "X-Http-Method-Override"):
                        self.assertNotIn(header, record["headers"])
                    if method == "HEAD":
                        self.assertEqual(payload, b"")
                for method in ("POST", "PUT", "DELETE", "PATCH"):
                    self.assertEqual(self.request(port, f"/api/backends/{server.name}/health", method)[0], 403)
        self.assertTrue(all(len(server.records) == 2 for server in (self.b0, self.b1, self.b2)))

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
        self.assertEqual(self.b2.records, [])

    def test_identical_session_and_csrf_cookies_remain_isolated_by_backend(self):
        jar = http.cookiejar.CookieJar()
        with self.nginx() as port:
            for backend in ("b0", "b1", "b2"):
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
            for server in (self.b0, self.b1, self.b2):
                path = f"/backends/{server.name}/admin-api/devices"
                browser_request = urllib.request.Request(f"https://console.test{path}")
                jar.add_cookie_header(browser_request)
                cookie_header = browser_request.get_header("Cookie")
                self.assertIn(f"CHECKINADMINSESSID=fixture-{server.name}", cookie_header)
                for other in {"b0", "b1", "b2"} - {server.name}:
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
        self.assertEqual(self.b2.records, [])

    def test_upstream_auth_failure_is_returned_without_switching_backends(self):
        with self.nginx() as port:
            status, _, payload = self.request(port, "/backends/b0/admin-api/unauthorized")
            self.assertEqual(status, 401)
            self.assertEqual(json.loads(payload)["backend"], "b0")
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(self.b1.records, [])
        self.assertEqual(self.b2.records, [])

    def test_tls_rejects_untrusted_certificates_and_wrong_hostnames(self):
        for invalid in (self.untrusted, self.wrong_name):
            with self.subTest(certificate=invalid.name):
                with self.nginx((invalid.origin, self.b1.origin)) as port:
                    self.assertEqual(self.request(port, "/backends/b0/admin-api/session")[0], 502)
                self.assertEqual(invalid.records, [])
                self.assertIn("localhost", invalid.names)
                self.assertEqual(self.b1.records, [])
                self.assertEqual(self.b2.records, [])

    def test_health_is_a_fixed_get_without_credentials_body_or_cookies(self):
        with self.nginx() as port:
            for server in (self.b0, self.b1, self.b2):
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
        self.assertEqual(len(self.b2.records), 1)

    def test_health_rejects_mutations_without_contacting_an_upstream(self):
        with self.nginx() as port:
            for backend in ("b0", "b1", "b2"):
                for method in ("POST", "PUT", "DELETE", "PATCH"):
                    with self.subTest(backend=backend, method=method):
                        status, _, _ = self.request(port, f"/backends/{backend}/health", method, "fixture")
                        self.assertIn(status, (403, 405))
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])
        self.assertEqual(self.b2.records, [])

    def test_health_head_uses_a_read_only_get_and_returns_no_body(self):
        with self.nginx() as port:
            for server in (self.b0, self.b1, self.b2):
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
