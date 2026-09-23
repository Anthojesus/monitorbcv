"""Detailed HTTPS probe. Same JSON contract as the Laravel HttpProbe."""

from __future__ import annotations

import hashlib
import json
import re
import socket
import ssl
import time
import uuid
from datetime import datetime, timezone
from http.client import HTTPConnection, HTTPSConnection
from typing import Any
from urllib.parse import urlparse

try:
    import truststore
except ImportError:  # pragma: no cover - optional on hosts without the package
    truststore = None


SCHEMA_VERSION = "1.1.0"
USER_AGENT = "MonitorBCV/1.1 (+https://monitorbcv.web.test)"
ANY_HTTP_STATUS = 0
DEFAULT_EXPECTED_STATUS = [200, 201, 204, 301, 302, 303, 304, 307, 308]
ISSUER_MARKERS = (
    "unable to get local issuer certificate",
    "self-signed certificate",
    "self signed certificate",
    "unable to verify the first certificate",
    "certificate signed by unknown authority",
)


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def ms_since(start: float) -> int:
    return int((time.perf_counter() - start) * 1000)


def ssl_context(verify: bool) -> ssl.SSLContext:
    if not verify:
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE
        return context

    if truststore is not None:
        return truststore.SSLContext(ssl.PROTOCOL_TLS_CLIENT)

    return ssl.create_default_context()


def classify_error(error: dict[str, Any] | None) -> str | None:
    if error is None:
        return None

    message = str(error.get("message") or "").lower()
    typ = str(error.get("type") or "").lower()

    if any(token in message for token in ("resolv", "dns", "getaddrinfo", "name or service not known")):
        return "dns"
    if "ssl" in message or "certificate" in message or "ssl" in typ:
        return "tls"
    if "timed out" in message or "timeout" in message:
        return "timeout"
    if "connect" in message:
        return "tcp"
    return "transport"


def is_untrusted_issuer(error: dict[str, Any] | None) -> bool:
    if error is None:
        return False

    message = str(error.get("message") or "").lower()
    if any(token in message for token in ("hostname", "altname", "expired")):
        return False

    return any(marker in message for marker in ISSUER_MARKERS)


def probe(target: dict[str, Any]) -> dict[str, Any]:
    started_at = now_iso()
    url = target["url"]
    method = target.get("method", "GET").upper()
    timeout = int(target.get("timeout_seconds", 15))
    expected = target.get("expected_status") or DEFAULT_EXPECTED_STATUS
    accept_any = ANY_HTTP_STATUS in expected
    keyword = target.get("expected_keyword")
    verify_ssl = bool(target.get("verify_ssl", True))

    parsed = urlparse(url)
    host = parsed.hostname or ""
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    path = parsed.path or "/"
    if parsed.query:
        path = f"{path}?{parsed.query}"

    error: dict[str, Any] | None = None
    dns: dict[str, Any] = {"hostname": host, "resolved_ips": [], "primary_ip": None, "records": [], "time_ms": None}
    tcp = {"remote_ip": None, "remote_port": port, "connect_ms": None}
    tls: dict[str, Any] = {"used": parsed.scheme == "https"}
    http: dict[str, Any] = {"status": None, "headers": {}, "body_bytes": 0, "title": None}
    timings = {"dns": None, "tcp": None, "tls": None, "ttfb": None, "download": None, "redirect": None, "total": None}
    body = b""
    issuer_fallback = False

    t0 = time.perf_counter()
    attempts = [verify_ssl]
    if verify_ssl:
        attempts.append(False)

    for index, verify in enumerate(attempts):
        try:
            t_dns = time.perf_counter()
            infos = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
            dns["time_ms"] = ms_since(t_dns)
            ips = sorted({item[4][0] for item in infos})
            dns["resolved_ips"] = ips
            dns["primary_ip"] = ips[0] if ips else None
            tcp["remote_ip"] = dns["primary_ip"]

            t_tcp = time.perf_counter()
            sock = socket.create_connection((host, port), timeout=timeout)
            timings["tcp"] = ms_since(t_tcp)

            if parsed.scheme == "https":
                context = ssl_context(verify)
                t_tls = time.perf_counter()
                ssock = context.wrap_socket(sock, server_hostname=host)
                timings["tls"] = ms_since(t_tls)
                cert = ssock.getpeercert() or {}
                cipher = ssock.cipher()
                not_after = cert.get("notAfter")
                days = None
                if not_after:
                    exp = datetime.strptime(not_after, "%b %d %H:%M:%S %Y %Z").replace(tzinfo=timezone.utc)
                    days = int((exp - datetime.now(timezone.utc)).total_seconds() // 86400)
                tls.update(
                    {
                        "verified": verify,
                        "trust": "os" if verify else ("issuer_untrusted" if index > 0 else "skipped"),
                        "version": ssock.version(),
                        "cipher": cipher[0] if cipher else None,
                        "certificate": {
                            "subject": cert.get("subject"),
                            "issuer": cert.get("issuer"),
                            "not_after": not_after,
                            "days_remaining": days,
                            "expiring_soon": days is not None and days <= 21,
                            "san": cert.get("subjectAltName") or [],
                        },
                        "handshake_ms": timings["tls"],
                    }
                )
                conn: HTTPConnection = HTTPSConnection(host, port, timeout=timeout, context=context)
                conn.sock = ssock
            else:
                conn = HTTPConnection(host, port, timeout=timeout)
                conn.sock = sock

            accept = "application/json, */*;q=0.8" if target.get("kind") == "health" else "*/*"
            headers = {"User-Agent": USER_AGENT, "Accept": accept, "Host": host}
            t_http = time.perf_counter()
            conn.request(method, path, headers=headers)
            response = conn.getresponse()
            timings["ttfb"] = ms_since(t_http)
            body = response.read()
            timings["download"] = ms_since(t_http) - (timings["ttfb"] or 0)
            raw_headers = {k.lower(): v for k, v in response.getheaders()}
            title = None
            match = re.search(r"<title[^>]*>(.*?)</title>", body.decode("utf-8", "ignore"), re.I | re.S)
            if match:
                title = re.sub(r"\s+", " ", match.group(1)).strip()
            parsed_json = None
            if body and len(body) <= 65536:
                try:
                    decoded = json.loads(body.decode("utf-8"))
                    if isinstance(decoded, dict):
                        parsed_json = decoded
                except ValueError:
                    parsed_json = None
            http = {
                "status": response.status,
                "reason": response.reason,
                "version": f"HTTP/{response.version / 10:.1f}" if isinstance(response.version, int) else str(response.version),
                "headers": raw_headers,
                "content_type": raw_headers.get("content-type"),
                "body_bytes": len(body),
                "body_sha256": hashlib.sha256(body).hexdigest() if body else None,
                "title": title,
                "server": raw_headers.get("server"),
                "powered_by": raw_headers.get("x-powered-by"),
                "json": parsed_json,
            }
            conn.close()
            error = None
            if index > 0:
                issuer_fallback = True
                tls["verified"] = False
                tls["trust"] = "issuer_untrusted"
            break
        except Exception as exc:  # noqa: BLE001 — probe must never crash the worker
            error = {"type": type(exc).__name__, "message": str(exc)}
            if index == 0 and verify_ssl and is_untrusted_issuer(error):
                continue
            break

    timings["dns"] = dns["time_ms"]
    timings["total"] = ms_since(t0)
    status = http.get("status")
    keyword_found = None if keyword is None else keyword.encode() in body
    if error:
        reason = classify_error(error) or "transport"
        up = False
    elif status is None:
        reason = "no_response"
        up = False
    elif accept_any:
        if keyword_found is False:
            reason = "keyword_missing"
            up = False
        else:
            listed = [code for code in expected if code != ANY_HTTP_STATUS] or DEFAULT_EXPECTED_STATUS
            reason = "ok" if status in listed else "http_reachable"
            up = True
    elif status not in expected:
        reason = "unexpected_status"
        up = False
    elif keyword_found is False:
        reason = "keyword_missing"
        up = False
    else:
        reason = "ok"
        up = True

    return {
        "schema_version": SCHEMA_VERSION,
        "type": "http_check",
        "check_id": str(uuid.uuid4()),
        "engine": "fastapi",
        "target": target,
        "started_at": started_at,
        "finished_at": now_iso(),
        "ok": up,
        "availability": {"up": up, "reason": reason},
        "request": {"method": method, "url": url, "final_url": url},
        "dns": dns,
        "tcp": tcp,
        "tls": tls,
        "http": http,
        "timings_ms": timings,
        "performance": {"ttfb_ms": timings["ttfb"], "total_ms": timings["total"]},
        "content_check": {"keyword": keyword, "keyword_found": keyword_found},
        "error": error,
    }


if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser()
    parser.add_argument("--url", required=True)
    parser.add_argument("--name", default="cli")
    args = parser.parse_args()
    print(json.dumps(probe({"id": None, "name": args.name, "url": args.url}), indent=2))
