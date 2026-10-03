#!/usr/bin/env python3
"""Synthetic, in-memory checks for the authenticated Bridge state endpoint."""
from __future__ import annotations

import ast
import hashlib
import hmac
import io
import json
import re
import time
from http import HTTPStatus
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = ROOT / "deploy/report-delivery-gateway-bridge/philips_folder_bridge.py"
JOB_ID = 9002
TENANT_ID = "2"
DESTINATION_ID = "6"
PACKAGE_HASH = "a" * 64
SECRET = b"synthetic-bridge-secret"


def load_handler() -> type:
    tree = ast.parse(BRIDGE.read_text(encoding="utf-8"), filename=str(BRIDGE))
    handler = next(node for node in tree.body if isinstance(node, ast.ClassDef) and node.name == "Handler")
    selected = {
        node.name
        for node in handler.body
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
        and node.name in {"respond", "do_GET", "reconcile_submission_package_state"}
    }
    handler.body = [node for node in handler.body if getattr(node, "name", None) in selected]
    handler.bases = []
    namespace: dict[str, object] = {
        "HTTPStatus": HTTPStatus,
        "hashlib": hashlib,
        "hmac": hmac,
        "io": io,
        "json": json,
        "LOG": SimpleNamespace(info=lambda *_args, **_kwargs: None, warning=lambda *_args, **_kwargs: None),
        "POLICY": SimpleNamespace(
            secret=SECRET,
            destination_id=6,
            allow_tenant_id=2,
            allow_destination_id=6,
            mode="single_test",
            allowed_job_id=JOB_ID,
        ),
        "MAX_CLOCK_SKEW_SECONDS": 60,
        "re": re,
        "time": time,
        "read_state": lambda _job_id: {},
    }
    module = ast.Module(body=[handler], type_ignores=[])
    ast.fix_missing_locations(module)
    exec(compile(module, str(BRIDGE), "exec"), namespace)
    return namespace["Handler"]  # type: ignore[return-value]


def signature(path: str, package_hash: str = PACKAGE_HASH, timestamp: str | None = None) -> str:
    timestamp = timestamp or str(int(time.time()))
    base = "\n".join(["GET", path, str(JOB_ID), TENANT_ID, DESTINATION_ID, package_hash, timestamp])
    return hmac.new(SECRET, base.encode(), hashlib.sha256).hexdigest()


def run_request(handler_type: type, state: dict[str, str], *, package_hash: str = PACKAGE_HASH, valid_signature: bool = True, job_id: int = JOB_ID) -> tuple[int, dict[str, str]]:
    path = f"/v1/philips-folder/package/{job_id}/state"
    timestamp = str(int(time.time()))
    headers = {
        "X-VOXEL-Job-ID": str(job_id),
        "X-VOXEL-Tenant-ID": TENANT_ID,
        "X-VOXEL-Destination-ID": DESTINATION_ID,
        "X-VOXEL-SHA256": package_hash,
        "X-VOXEL-Timestamp": timestamp,
        "X-VOXEL-Signature": signature(path, package_hash, timestamp) if valid_signature else "invalid",
    }
    Handler = handler_type
    handler = Handler.__new__(Handler)
    handler.path = path
    handler.headers = headers
    handler.wfile = io.BytesIO()
    captured: dict[str, object] = {}
    handler.send_response = lambda status: captured.__setitem__("status", int(status))
    handler.send_header = lambda *_args: None
    handler.end_headers = lambda: None
    handler_type_namespace = handler_type.__dict__
    # The extracted method resolves read_state through its original globals.
    handler_type_namespace  # keep the setup explicit for static analyzers.
    import sys
    module_globals = sys.modules[handler_type.__module__].__dict__ if handler_type.__module__ in sys.modules else None
    # The AST loader has no module object; replace the function global directly.
    handler.do_GET.__func__.__globals__["read_state"] = lambda _job_id: state
    handler.do_GET()
    return int(captured["status"]), json.loads(handler.wfile.getvalue().decode())


def main() -> None:
    Handler = load_handler()
    delivered = {
        "state": "delivered",
        "sha256": PACKAGE_HASH,
        "package_identity": PACKAGE_HASH,
        "package_verified": "PASS",
        "tenant_id": TENANT_ID,
        "destination_id": DESTINATION_ID,
        "reference": "gateway-philips-folder:" + PACKAGE_HASH[:16],
    }
    status, body = run_request(Handler, delivered)
    assert status == HTTPStatus.OK and body["package_verified"] == "PASS"
    assert body["package_identity"] == PACKAGE_HASH

    status, body = run_request(Handler, {**delivered, "package_identity": "b" * 64})
    assert status == HTTPStatus.CONFLICT and body["state"] == "conflict"

    status, body = run_request(Handler, {})
    assert status == HTTPStatus.NOT_FOUND and body["state"] == "not_found"

    status, body = run_request(Handler, delivered, valid_signature=False)
    assert status == HTTPStatus.UNAUTHORIZED and body["error"] == "invalid_signature"

    status, body = run_request(Handler, delivered, job_id=JOB_ID + 1)
    assert status == HTTPStatus.FORBIDDEN and body["error"] == "policy_rejected"

    print("PHILIPS_HTTP_RECONCILIATION_SYNTHETIC_OK")


if __name__ == "__main__":
    main()
