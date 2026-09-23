import os

from fastapi import HTTPException

os.environ.setdefault("MONITOR_API_TOKEN", "secret")

from main import require_bearer  # noqa: E402


def test_checks_rejects_without_bearer():
    try:
        require_bearer(None)
    except HTTPException as exception:
        assert exception.status_code == 401
        return

    raise AssertionError("Missing bearer must be rejected")


def test_checks_rejects_wrong_bearer():
    try:
        require_bearer("Bearer wrong")
    except HTTPException as exception:
        assert exception.status_code == 401
        return

    raise AssertionError("Wrong bearer must be rejected")


def test_checks_accepts_configured_bearer():
    require_bearer("Bearer secret")


def test_health_stays_public():
    from main import health

    payload = health()

    assert payload["status"] == "ok"
    assert payload["probe"] == "ok"


def test_logs_require_bearer():
    test_checks_rejects_without_bearer()


if __name__ == "__main__":
    test_checks_rejects_without_bearer()
    test_checks_rejects_wrong_bearer()
    test_checks_accepts_configured_bearer()
    test_health_stays_public()
    test_logs_require_bearer()
    print("ok")
