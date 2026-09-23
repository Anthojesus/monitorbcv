import os
import time
from collections import deque
from datetime import datetime, timezone

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from pydantic import BaseModel, Field

from probe import probe

app = FastAPI(title="Monitor BCV", version="1.1.0")

LOGS: deque[dict] = deque(maxlen=120)


class TargetIn(BaseModel):
    id: int | None = None
    name: str
    url: str
    kind: str = "http"
    method: str = "GET"
    expected_status: list[int] = Field(default_factory=lambda: [200, 201, 204, 301, 302, 303, 304, 307, 308])
    expected_keyword: str | None = None
    verify_ssl: bool = True
    timeout_seconds: int = 15


class CheckRequest(BaseModel):
    target: TargetIn


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def record(event: dict) -> None:
    LOGS.appendleft({"at": now_iso(), **event})


def require_bearer(authorization: str | None = Header(default=None)) -> None:
    token = os.getenv("MONITOR_API_TOKEN", "").strip()

    if token == "":
        raise HTTPException(status_code=401, detail="MONITOR_API_TOKEN is not configured")

    scheme, _, value = (authorization or "").partition(" ")

    if scheme.lower() != "bearer" or value != token:
        raise HTTPException(status_code=401, detail="Unauthorized")


@app.middleware("http")
async def capture_http_log(request: Request, call_next):
    started = time.perf_counter()
    response = await call_next(request)
    path = request.url.path
    elapsed = int((time.perf_counter() - started) * 1000)

    if path in {"/health", "/v1/logs"} and response.status_code < 400:
        return response

    record({
        "kind": "http",
        "method": request.method,
        "path": path,
        "status": response.status_code,
        "ms": elapsed,
        "client": request.client.host if request.client else None,
    })

    return response


@app.get("/health")
def health() -> dict[str, str]:
    return {
        "status": "ok",
        "service": "monitor-bcv",
        "probe": "ok",
        "version": app.version,
    }


@app.get("/v1/logs")
def list_logs(_: None = Depends(require_bearer)) -> dict:
    return {
        "service": "monitor-bcv",
        "version": app.version,
        "probe": "ok",
        "count": len(LOGS),
        "events": list(LOGS),
    }


@app.post("/v1/checks")
def create_check(request: CheckRequest, _: None = Depends(require_bearer)) -> dict:
    started = time.perf_counter()
    result = probe(request.target.model_dump())
    record({
        "kind": "check",
        "ok": bool(result.get("ok")),
        "target": request.target.name,
        "url": request.target.url,
        "status": (result.get("http") or {}).get("status") if isinstance(result.get("http"), dict) else None,
        "reason": ((result.get("availability") or {}).get("reason") if isinstance(result.get("availability"), dict) else None),
        "ms": int((time.perf_counter() - started) * 1000),
    })

    return result
