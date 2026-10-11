"""Real local TLS coverage for the opt-in mini-program primary/backup proxy."""
from __future__ import annotations

import contextlib
import http.client
import http.server
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

import test_frontend_proxy as frontend
from test_frontend_proxy import TEMPLATE, render, test_environment

REPO = Path(__file__).resolve().parents[2]
SCRIPT = REPO / "dockerfile/50-configure-miniprogram.sh"


def configure(output, settings):
    env = test_environment()
    for key in list(env):
        if key.startswith("APP_MP_API_"):
            env.pop(key)
    env.update(settings)
    env["NGINX_FRONTEND_OUTPUT_PATH"] = str(output)
    # Before implementation, the shipped entrypoint has no mini-program step.
    if not SCRIPT.exists():
        return subprocess.CompletedProcess([], 0, "", "")
    return subprocess.run(["/bin/sh", str(SCRIPT)], env=env, text=True,
                          capture_output=True, timeout=10)


class MiniProgramRendererTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="miniprogram-render-")
        self.addCleanup(self.tmp.cleanup)
        self.output = Path(self.tmp.name) / "server.conf"
        self.assertEqual(render(self.output, TEMPLATE, ("https://localhost:443",)).returncode, 0)

    def settings(self, **overrides):
        return {"APP_MP_API_ENABLED": "1", "APP_MP_API_PRIMARY_URL": "https://primary.example",
                "APP_MP_API_BACKUP_URL": "https://backup.example",
                "APP_MP_API_UPSTREAM_HOST": "shared.example", **overrides}

    def test_enabled_configuration_creates_a_shared_dynamic_primary_backup_pool(self):
        result = configure(self.output, self.settings())
        self.assertEqual(result.returncode, 0, result.stderr)
        config = self.output.read_text()
        self.assertIn("upstream miniprogram_api", config)
        self.assertIn("zone miniprogram_api 256k", config)
        self.assertIn("primary.example:443 resolve max_fails=1 fail_timeout=10s", config)
        self.assertIn("backup.example:443 resolve max_fails=1 fail_timeout=10s backup", config)
        self.assertIn("resolver 127.0.0.11", config)
        self.assertNotIn("non_idempotent", config)
        dockerfile = (REPO / "dockerfile/FrontendDockerfile").read_text()
        self.assertIn("COPY dockerfile/50-configure-miniprogram.sh", dockerfile)

    def test_disabled_step_keeps_existing_fixed_routes_unchanged(self):
        previous = self.output.read_text()
        result = configure(self.output, {})
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.output.read_text(), previous)

    def test_invalid_settings_do_not_replace_valid_web_configuration(self):
        invalid_origins = ["", "http://example.com", "https://user@host", "https://host/path",
                           "https://host?x=1", "https://host#x", "https://host\n", "https://host;",
                           "https://host:0", "https://host:65536", "https://[::1]", "https://-host",
                           "https://" + "a" * 64 + ".example"]
        cases = [{key: value} for key in ("APP_MP_API_PRIMARY_URL", "APP_MP_API_BACKUP_URL")
                 for value in invalid_origins]
        cases += [{"APP_MP_API_UPSTREAM_HOST": value} for value in
                  ("", "shared.example:443", "shared.example/path", "shared.example\n", "$host", "-host")]
        cases += [{"APP_MP_API_RESOLVER": value} for value in
                  ("", "127.0.0.1;include", "127.0.0.1\n", "localhost", "127.0.0.1:0", "999.0.0.1")]
        cases += [{"APP_MP_API_ENABLED": "true"}, {"APP_MP_API_ENABLED": "1\n"}]
        previous = self.output.read_text()
        for overrides in cases:
            with self.subTest(overrides=overrides):
                result = configure(self.output, self.settings(**overrides))
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(self.output.read_text(), previous)


class LocalDNS:
    """Answer only fixture host A records; never forwards a DNS query externally."""
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
            permitted = name in ("primary.fixture.test", "backup.fixture.test") and qtype == 1
            header = query[:2] + struct.pack("!HHHHH", 0x8180, 1, int(permitted), 0, 0)
            answer = (b"\xc0\x0c" + struct.pack("!HHIH", 1, 1, 1, 4) + socket.inet_aton("127.0.0.1")) if permitted else b""
            self.socket.sendto(header + question + answer, peer)

    def close(self):
        self.running = False
        self.thread.join(timeout=2)
        self.socket.close()


class MiniProgramHandler(http.server.BaseHTTPRequestHandler):
    def handle(self):
        try:
            super().handle()
        except (ConnectionResetError, ssl.SSLError):
            # Certificate rejection can reset TLS before any HTTP request.
            pass

    def handle_request(self):
        controller = self.server.controller
        body = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        record = {"backend": controller.name, "method": self.command, "path": self.path,
                  "body": body.decode(), "headers": dict(self.headers.items()),
                  "sni": getattr(self.connection, "fixture_sni", None)}
        with controller.record_lock:
            controller.records.append(record)
        if controller.mode == "drop":
            self.connection.shutdown(socket.SHUT_RDWR)
            self.connection.close()
            return
        if controller.mode == "timeout":
            time.sleep(21)
            return
        status = controller.mode if isinstance(controller.mode, int) else 200
        payload = json.dumps(record).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Set-Cookie", "must-not-leak=yes; Path=/")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(payload)

    do_GET = do_HEAD = do_POST = do_PUT = do_DELETE = do_PATCH = do_OPTIONS = handle_request

    def log_message(self, *_args):
        pass


@unittest.skipUnless(os.environ.get("NGINX_TEST_BINARY") or shutil.which("nginx"),
                     "Real Nginx required: set NGINX_TEST_BINARY")
class MiniProgramProxyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        # Reuse real CA/trusted/wrong-host/untrusted TLS fixture creation.
        frontend.NginxProxyTests.setUpClass.__func__(cls)
        for server in (cls.b0, cls.b1, cls.b2, cls.untrusted, cls.wrong_name):
            server.mode = "ok"
            for listener in server.listeners:
                listener.controller = server
                listener.RequestHandlerClass = MiniProgramHandler

    def setUp(self):
        for server in (self.b0, self.b1, self.b2, self.untrusted, self.wrong_name):
            server.clear()
            server.mode = "ok"

    @contextlib.contextmanager
    def nginx(self, primary=None, backup=None, enabled=True):
        with tempfile.TemporaryDirectory(prefix="miniprogram-nginx-") as temporary:
            directory = Path(temporary)
            (directory / "logs").mkdir()
            root = directory / "html"
            root.mkdir()
            (root / "index.html").write_text("local frontend")
            rendered = directory / "server.conf"
            result = render(rendered, TEMPLATE, (self.b0.origin, self.b1.origin),
                            runtime_config=root / "runtime-config.json")
            self.assertEqual(result.returncode, 0, result.stderr)
            dns = LocalDNS()
            try:
                settings = {"APP_MP_API_ENABLED": "1" if enabled else "0",
                            "APP_MP_API_PRIMARY_URL": primary or f"https://primary.fixture.test:{self.b0.server_port}",
                            "APP_MP_API_BACKUP_URL": backup or f"https://backup.fixture.test:{self.b1.server_port}",
                            "APP_MP_API_UPSTREAM_HOST": "localhost",
                            "APP_MP_API_RESOLVER": f"127.0.0.1:{dns.port}"}
                result = configure(rendered, settings)
                self.assertEqual(result.returncode, 0, result.stderr)
                server = rendered.read_text()
                authorities = re.findall(r"server ([^ ;]+) resolve", server)
                self.assertTrue(all(re.fullmatch(r"(?:primary|backup)\.fixture\.test:[0-9]+|127\.0\.0\.1:[0-9]+", item)
                                    for item in authorities), "Never contact production upstreams")
                with socket.socket() as reservation:
                    reservation.bind(("127.0.0.1", 0))
                    port = reservation.getsockname()[1]
                # Relocate only container listen/root/CA resources, preserving proxy rules.
                server = server.replace("/usr/share/nginx/html", str(root))
                server = server.replace("/etc/ssl/certs/ca-certificates.crt", str(self.ca))
                server, count = re.subn(r"\blisten\s+80\s*;", f"listen 127.0.0.1:{port};", server)
                self.assertEqual(count, 1)
                rendered.write_text(server)
                config = directory / "nginx.conf"
                self.error_log = directory / "error.log"
                config.write_text("daemon off;\nmaster_process off;\nworker_processes 1;\n"
                                  f"pid {directory}/nginx.pid;\nerror_log {self.error_log} info;\n"
                                  "events { worker_connections 128; }\nhttp {\naccess_log off;\n"
                                  f"client_body_temp_path {directory}/client_temp;\n"
                                  f"proxy_temp_path {directory}/proxy_temp;\ninclude {rendered};\n}}\n")
                command = [str(self.binary), "-p", str(directory), "-c", str(config)]
                syntax = subprocess.run(command + ["-t"], capture_output=True, text=True, timeout=10)
                self.assertEqual(syntax.returncode, 0, syntax.stderr)
                with (directory / "process.log").open("w+") as log:
                    process = subprocess.Popen(command, stdout=log, stderr=log)
                    try:
                        deadline = time.monotonic() + 5
                        while time.monotonic() < deadline:
                            if process.poll() is not None:
                                log.seek(0)
                                self.fail(log.read())
                            try:
                                with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                                    if not authorities or not enabled or len(set(dns.queries)) == 2 or primary:
                                        break
                            except OSError:
                                pass
                            time.sleep(0.025)
                        else:
                            self.fail("Nginx did not start or resolve fixture upstreams")
                        # Resolver response handling is asynchronous to UDP receipt.
                        time.sleep(0.05)
                        yield port
                    finally:
                        process.terminate()
                        process.wait(timeout=3)
            finally:
                dns.close()

    request = frontend.NginxProxyTests.request

    def test_primary_preserves_uri_query_bearer_host_and_sni_but_strips_cookies(self):
        with self.nginx() as port:
            status, headers, payload = self.request(port, "/v2/devices/42?expand=setup&x=a%2Fb&x=2",
                                                    headers={"Authorization": "Bearer fixture-only",
                                                             "Cookie": "CHECKINADMINSESSID=browser",
                                                             "X-Forwarded-For": "203.0.113.7"})
            self.assertEqual(status, 200, self.error_log.read_text())
            record = json.loads(payload)
            self.assertEqual(record["backend"], "b0")
            self.assertEqual(record["path"], "/v2/devices/42?expand=setup&x=a%2Fb&x=2")
            self.assertEqual(record["headers"]["Authorization"], "Bearer fixture-only")
            self.assertEqual(record["headers"]["Host"], "localhost")
            self.assertEqual(record["headers"]["X-Forwarded-For"], "127.0.0.1")
            self.assertEqual(record["sni"], "localhost")
            self.assertNotIn("Cookie", record["headers"])
            self.assertIsNone(headers.get("Set-Cookie"))
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(self.b1.records, [])

    def test_read_transport_failure_uses_backup_and_primary_recovers_after_quarantine(self):
        with self.nginx() as port:
            self.b0.mode = "drop"
            status, _, payload = self.request(port, "/v2/devices/manage")
            self.assertEqual(status, 200, self.error_log.read_text())
            self.assertEqual(json.loads(payload)["backend"], "b1")
            self.b0.mode = "ok"
            self.assertEqual(json.loads(self.request(port, "/v2/devices")[2])["backend"], "b1")
            # Nginx uses integer seconds and excludes peers while elapsed <= 10.
            time.sleep(11.1)
            self.assertEqual(json.loads(self.request(port, "/v2/devices")[2])["backend"], "b0")
        self.assertEqual(len(self.b0.records), 2)
        self.assertEqual(len(self.b1.records), 2)

    def test_gateway_errors_retry_but_application_errors_do_not(self):
        for status in (502, 503, 504, 400, 401, 403, 404, 409, 429, 500):
            with self.subTest(status=status):
                self.b0.clear()
                self.b1.clear()
                self.b0.mode = status
                with self.nginx() as port:
                    actual, _, payload = self.request(port, "/v2/devices/42")
                    retry = status in (502, 503, 504)
                    self.assertEqual(actual, 200 if retry else status, self.error_log.read_text())
                    self.assertEqual(json.loads(payload)["backend"], "b1" if retry else "b0")
                self.assertEqual(len(self.b0.records), 1)
                self.assertEqual(len(self.b1.records), int(retry))

    def test_mutations_and_unverified_get_routes_are_never_replayed_after_send(self):
        cases = [(method, "/v2/devices/42") for method in ("POST", "PUT", "DELETE", "PATCH")]
        cases += [("GET", path) for path in ("/v2/server/info?uuid=fixture", "/v2/server/refresh",
                                               "/v2/wechat/code?code=fixture", "/v2/unknown", "/v2/%64evices/42")]
        cases += [("HEAD", "/v2/server/info?uuid=fixture")]
        for method, path in cases:
            with self.subTest(method=method, path=path):
                self.b0.clear()
                self.b1.clear()
                self.b0.mode = "drop"
                with self.nginx() as port:
                    status, _, _ = self.request(port, path, method, "fixture-write" if method != "HEAD" else None,
                                                {"Authorization": "Bearer write-fixture", "Cookie": "browser=1"})
                    self.assertEqual(status, 502, self.error_log.read_text())
                    self.assertEqual(len(self.b0.records), 1)
                    self.assertEqual(self.b0.records[0]["method"], method)
                    self.assertEqual(self.b0.records[0]["path"], path)
                    self.assertEqual(self.b0.records[0]["headers"]["Authorization"], "Bearer write-fixture")
                    self.assertNotIn("Cookie", self.b0.records[0]["headers"])
                    self.assertEqual(self.b1.records, [])
                    # A separate later request can use the backup after transport failure.
                    self.assertEqual(json.loads(self.request(port, "/v2/devices")[2])["backend"], "b1")

    def test_device_queries_are_never_replayed_even_for_get_and_head(self):
        paths = ("/v2/devices/42?expand=setup", "/v2/devices/manage?expand=setup",
                 "/v2/devices?expand=setup", "/v2/devices/42?page=2", "/v2/devices/42?_method=PUT")
        for method in ("GET", "HEAD"):
            for path in paths:
                with self.subTest(method=method, path=path):
                    self.b0.clear()
                    self.b1.clear()
                    self.b0.mode = "drop"
                    with self.nginx() as port:
                        self.assertEqual(self.request(port, path, method)[0], 502)
                    self.assertEqual(len(self.b0.records), 1)
                    self.assertEqual(self.b0.records[0]["path"], path)
                    self.assertEqual(self.b1.records, [])

    def test_method_override_get_requests_are_forwarded_once_without_replay(self):
        for mode in ("drop", 502, 503, 504):
            for override in ("PUT", "DELETE", "PATCH", "GET", ":/v2/devices?injected=1"):
                with self.subTest(mode=mode, override=override):
                    self.b0.clear()
                    self.b1.clear()
                    self.b0.mode = mode
                    with self.nginx() as port:
                        status, _, payload = self.request(
                            port, "/v2/devices/42", headers={"X-Http-Method-Override": override},
                        )
                        self.assertEqual(status, 502 if mode == "drop" else mode)
                        if mode != "drop":
                            self.assertEqual(json.loads(payload)["backend"], "b0")
                    self.assertEqual(len(self.b0.records), 1)
                    self.assertEqual(self.b0.records[0]["headers"]["X-Http-Method-Override"], override)
                    self.assertEqual(self.b1.records, [])

    def test_post_form_method_override_is_forwarded_once_without_replay(self):
        self.b0.mode = "drop"
        with self.nginx() as port:
            status, _, _ = self.request(
                port, "/v2/devices/42", "POST", "_method=DELETE&fixture=1",
                {"Content-Type": "application/x-www-form-urlencoded", "X-Http-Method-Override": "GET"},
            )
            self.assertEqual(status, 502)
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(self.b0.records[0]["method"], "POST")
        self.assertEqual(self.b0.records[0]["body"], "_method=DELETE&fixture=1")
        self.assertEqual(self.b1.records, [])

    def test_health_clears_method_override_even_during_failover(self):
        self.b0.mode = "drop"
        with self.nginx() as port:
            status, _, payload = self.request(
                port, "/mp-health?_method=DELETE", "GET", "_method=PATCH",
                {"X-Http-Method-Override": "PUT", "Content-Type": "application/x-www-form-urlencoded"},
            )
            self.assertEqual(status, 200)
            self.assertEqual(json.loads(payload)["backend"], "b1")
        for server in (self.b0, self.b1):
            self.assertEqual(len(server.records), 1)
            record = server.records[0]
            self.assertEqual(record["method"], "GET")
            self.assertEqual(record["path"], "/health")
            self.assertEqual(record["body"], "")
            self.assertNotIn("X-Http-Method-Override", record["headers"])

    def test_gateway_responses_never_replay_mutations(self):
        for method in ("POST", "PUT", "DELETE", "PATCH"):
            for status in (502, 503, 504):
                with self.subTest(method=method, status=status):
                    self.b0.clear()
                    self.b1.clear()
                    self.b0.mode = status
                    with self.nginx() as port:
                        actual, headers, payload = self.request(port, "/v2/devices/42", method, "fixture-body")
                        self.assertEqual(actual, status)
                        self.assertEqual(json.loads(payload)["body"], "fixture-body")
                        self.assertIsNone(headers.get("Set-Cookie"))
                    self.assertEqual(len(self.b0.records), 1)
                    self.assertEqual(self.b1.records, [])

    def test_audited_head_and_scene_reads_retry(self):
        for method, path in (("HEAD", "/v2/devices/42"), ("GET", "/v2/server/scenes"),
                             ("GET", "/v2/server/scenes?expand=setup")):
            with self.subTest(method=method, path=path):
                self.b0.clear()
                self.b1.clear()
                self.b0.mode = "drop"
                with self.nginx() as port:
                    status, _, body = self.request(port, path, method)
                    self.assertEqual(status, 200)
                    if method == "HEAD":
                        self.assertEqual(body, b"")
                self.assertEqual(len(self.b0.records), 1)
                self.assertEqual(len(self.b1.records), 1)
                self.assertEqual(self.b1.records[0]["method"], method)

    def test_read_timeout_uses_backup_once(self):
        self.b0.mode = "timeout"
        with self.nginx() as port:
            connection = http.client.HTTPConnection("127.0.0.1", port, timeout=28)
            try:
                started = time.monotonic()
                connection.request("GET", "/v2/devices")
                response = connection.getresponse()
                self.assertEqual(response.status, 200)
                self.assertEqual(json.loads(response.read())["backend"], "b1")
                self.assertGreaterEqual(time.monotonic() - started, 19)
                self.assertLess(time.monotonic() - started, 26)
                self.assertIn("upstream timed out", self.error_log.read_text())
            finally:
                connection.close()
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(len(self.b1.records), 1)

    def test_enabled_proxy_keeps_fixed_web_routes_and_browser_cookies(self):
        with self.nginx() as port:
            self.b0.mode = "drop"
            self.assertEqual(json.loads(self.request(port, "/v2/devices")[2])["backend"], "b1")
            # B0's fixed Web route must not borrow the mini-program failover pool.
            self.assertEqual(self.request(port, "/backends/b0/admin-api/session")[0], 502)
            self.b0.mode = "ok"
            status, headers, body = self.request(port, "/backends/b0/admin-api/session",
                                                 headers={"Cookie": "browser-session=fixture"})
            self.assertEqual(status, 200)
            self.assertEqual(json.loads(body)["backend"], "b0")
            self.assertEqual(json.loads(body)["headers"]["Cookie"], "browser-session=fixture")
            self.assertIn("Path=/backends/b0/", headers.get("Set-Cookie"))

    def test_mutation_connection_failure_does_not_retry_before_send(self):
        with socket.socket() as closed:
            closed.bind(("127.0.0.1", 0))
            unused = closed.getsockname()[1]
        with self.nginx(primary=f"https://127.0.0.1:{unused}") as port:
            self.assertEqual(self.request(port, "/v2/site/login", "POST", "fixture-write")[0], 502)
            self.assertEqual(self.b1.records, [])
            self.assertEqual(json.loads(self.request(port, "/v2/devices")[2])["backend"], "b1")

    def test_both_backends_failing_get_only_one_attempt_each(self):
        self.b0.mode = self.b1.mode = "drop"
        with self.nginx() as port:
            started = time.monotonic()
            self.assertEqual(self.request(port, "/v2/devices")[0], 502)
            self.assertLess(time.monotonic() - started, 5)
        self.assertEqual(len(self.b0.records), 1)
        self.assertEqual(len(self.b1.records), 1)

    def test_tls_chain_and_shared_hostname_are_verified(self):
        for server in (self.untrusted, self.wrong_name):
            with self.subTest(server=server.name):
                with self.nginx(primary=f"https://127.0.0.1:{server.server_port}") as port:
                    self.assertEqual(json.loads(self.request(port, "/v2/devices")[2])["backend"], "b1")
                    self.assertIn("certificate", self.error_log.read_text())
                self.assertEqual(server.records, [])

    def test_health_is_fixed_credential_free_read_only_get_and_uses_failover(self):
        with self.nginx() as port:
            for method in ("GET", "HEAD"):
                status, headers, body = self.request(port, "/mp-health?injected=yes", method,
                                                     headers={"Authorization": "Bearer no-leak", "Cookie": "no-leak=1"})
                self.assertEqual(status, 200)
                record = self.b0.records[-1]
                self.assertEqual(record["path"], "/health")
                self.assertEqual(record["method"], "GET")
                self.assertNotIn("Cookie", record["headers"])
                self.assertNotIn("Authorization", record["headers"])
                self.assertEqual(record["body"], "")
                self.assertIsNone(headers.get("Set-Cookie"))
                if method == "HEAD":
                    self.assertEqual(body, b"")
            calls = len(self.b0.records)
            for method in ("POST", "PUT", "DELETE", "PATCH"):
                self.assertEqual(self.request(port, "/mp-health", method, "must-not-send")[0], 403)
            self.assertEqual(len(self.b0.records), calls)
            self.b0.mode = "drop"
            self.assertEqual(json.loads(self.request(port, "/mp-health")[2])["backend"], "b1")

    def test_disabled_and_arbitrary_routes_never_reach_upstreams(self):
        with self.nginx(enabled=False) as port:
            for path in ("/v2/devices", "/mp-health"):
                self.assertEqual(self.request(port, path)[0], 404)
        with self.nginx() as port:
            for path in ("/admin-api/session", "/backends/b0/v2/devices", "/mp-health/", "/mp-api-write"):
                self.assertEqual(self.request(port, path)[0], 404)
        self.assertEqual(self.b0.records, [])
        self.assertEqual(self.b1.records, [])


if __name__ == "__main__":
    unittest.main()
