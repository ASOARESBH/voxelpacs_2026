#!/usr/bin/env python3
"""Ponte controlada API -> gateway para uma única devolutiva DICOM Encapsulated PDF.

O serviço não é um listener DICOM e não aceita destinos, AEs, jobs ou payloads
arbitrários. A associação para o PACS externo é criada somente pelo gateway
através do WireGuard, com C-ECHO imediatamente antes do C-STORE.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
import secrets
import ssl
import subprocess
import sys
import tempfile
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import NoReturn

MAX_BYTES = 20 * 1024 * 1024
MAX_CLOCK_SKEW_SECONDS = 60
ROOT = Path("/var/lib/voxelpacs/report-delivery-gateway")


def state_file(job_id: int) -> Path:
    """Mantém a trava de tentativa única separada por job aceito pela policy."""
    return ROOT / f"attempted-job-{job_id}.json"


def env(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"missing_required_setting:{name}")
    return value


class Policy:
    def __init__(self) -> None:
        self.bind_ip = env("BRIDGE_BIND_IP")
        self.bind_port = int(env("BRIDGE_BIND_PORT"))
        self.mode = os.environ.get("BRIDGE_MODE", "controlled_job").strip()
        self.job_id = int(os.environ.get("BRIDGE_ALLOW_JOB_ID", "0"))
        self.tenant_id = int(os.environ.get("BRIDGE_ALLOW_TENANT_ID", "0"))
        self.destination_id = int(os.environ.get("BRIDGE_ALLOW_DESTINATION_ID", "0"))
        self.target_host = env("BRIDGE_TARGET_HOST")
        self.target_port = int(env("BRIDGE_TARGET_PORT"))
        self.calling_ae = env("BRIDGE_CALLING_AE")
        self.called_ae = env("BRIDGE_CALLED_AE")
        self.secret = Path(env("BRIDGE_HMAC_FILE")).read_text(encoding="utf-8").strip().encode("utf-8")
        self.ca_file = env("BRIDGE_CLIENT_CA_FILE")
        self.server_cert = env("BRIDGE_SERVER_CERT_FILE")
        self.server_key = env("BRIDGE_SERVER_KEY_FILE")
        if not self.secret or not (1 <= self.target_port <= 65535):
            raise RuntimeError("invalid_bridge_policy")
        if self.mode == "controlled_job" and self.job_id <= 0:
            raise RuntimeError("invalid_controlled_job_policy")
        if self.mode == "tenant_destination" and (self.tenant_id <= 0 or self.destination_id <= 0):
            raise RuntimeError("invalid_tenant_destination_policy")
        if self.mode not in {"controlled_job", "tenant_destination"}:
            raise RuntimeError("invalid_bridge_mode")
        if len(self.calling_ae) > 16 or len(self.called_ae) > 16:
            raise RuntimeError("invalid_bridge_ae")

    @property
    def expected_path(self) -> str:
        if self.mode == "controlled_job":
            return f"/v1/report-delivery/{self.job_id}"
        return f"/v1/report-delivery/tenant/{self.tenant_id}/destination/{self.destination_id}"

    def accepts_job(self, job_id: int, tenant_id: int, destination_id: int) -> bool:
        if self.mode == "controlled_job":
            return job_id == self.job_id
        return job_id > 0 and tenant_id == self.tenant_id and destination_id == self.destination_id


POLICY = Policy()
ROOT.mkdir(mode=0o700, parents=True, exist_ok=True)
os.chmod(ROOT, 0o700)
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
    stream=sys.stdout,
)
LOG = logging.getLogger("report_delivery_gateway")


def state(job_id: int) -> dict[str, object]:
    try:
        value = json.loads(state_file(job_id).read_text(encoding="utf-8"))
        return value if isinstance(value, dict) else {}
    except FileNotFoundError:
        return {}
    except (OSError, json.JSONDecodeError):
        return {"state": "unreadable"}


def write_state(job_id: int, value: dict[str, object]) -> None:
    target = state_file(job_id)
    temp = target.with_suffix(".tmp")
    temp.write_text(json.dumps(value, sort_keys=True), encoding="utf-8")
    os.chmod(temp, 0o600)
    os.replace(temp, target)


def invoke_dicom_scu(artifact: Path) -> tuple[bool, str]:
    container_artifact = f"/tmp/voxel-report-{secrets.token_hex(16)}.dcm"
    script_path = Path("/opt/voxelpacs/report-delivery-gateway/dicom_scu.py")
    copied = False
    try:
        copy = subprocess.run(
            ["/usr/bin/docker", "cp", str(artifact), f"voxelpacs-dicom-gateway:{container_artifact}"],
            capture_output=True,
            timeout=20,
            check=False,
        )
        if copy.returncode != 0:
            return False, "container_copy_failed"
        copied = True
        run = subprocess.run(
            [
                "/usr/bin/docker", "exec", "-u", "0", "-i", "voxelpacs-dicom-gateway", "python", "-",
                container_artifact, POLICY.target_host, str(POLICY.target_port), POLICY.calling_ae, POLICY.called_ae,
            ],
            input=script_path.read_bytes(),
            capture_output=True,
            timeout=50,
            check=False,
        )
        if run.returncode != 0:
            output = run.stdout.decode("utf-8", errors="replace").strip().splitlines()
            code = output[-1].strip() if output else ""
            allowed_codes = {
                "invalid_arguments", "invalid_port", "policy_rejected", "invalid_artifact", "invalid_dicom",
                "unsupported_sop_class", "missing_encapsulated_pdf", "association_rejected", "cecho_failed", "cstore_failed",
            }
            return False, code if code in allowed_codes else "dicom_scu_execution_failed"
        return True, "stored"
    except (OSError, subprocess.TimeoutExpired):
        return False, "dicom_gateway_execution_failed"
    finally:
        if copied:
            subprocess.run(
                ["/usr/bin/docker", "exec", "-u", "0", "voxelpacs-dicom-gateway", "rm", "-f", container_artifact],
                capture_output=True,
                timeout=10,
                check=False,
            )


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "VOXEL-Report-Bridge"

    def log_message(self, format: str, *args: object) -> None:
        # Não reflita paths, headers ou payloads de cliente em log.
        return

    def respond(self, status: HTTPStatus, body: dict[str, str]) -> None:
        payload = json.dumps(body, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(payload)

    def do_POST(self) -> None:  # noqa: N802
        expected_path = POLICY.expected_path
        if self.path != expected_path:
            self.respond(HTTPStatus.NOT_FOUND, {"error": "not_found"})
            return

        job_id = self.headers.get("X-VOXEL-Job-ID", "")
        tenant_id = self.headers.get("X-VOXEL-Tenant-ID", "")
        destination_id = self.headers.get("X-VOXEL-Destination-ID", "")
        timestamp = self.headers.get("X-VOXEL-Timestamp", "")
        supplied_hash = self.headers.get("X-VOXEL-SHA256", "").lower()
        signature = self.headers.get("X-VOXEL-Signature", "")
        content_length = self.headers.get("Content-Length", "")
        try:
            content_length_int = int(content_length)
            timestamp_int = int(timestamp)
            job_id_int = int(job_id)
            tenant_id_int = int(tenant_id)
            destination_id_int = int(destination_id)
        except ValueError:
            self.respond(HTTPStatus.BAD_REQUEST, {"error": "invalid_headers"})
            return
        if not POLICY.accepts_job(job_id_int, tenant_id_int, destination_id_int) or not (256 <= content_length_int <= MAX_BYTES):
            self.respond(HTTPStatus.FORBIDDEN, {"error": "policy_rejected"})
            return
        if state(job_id_int).get("state") in {"attempted", "delivered"}:
            self.respond(HTTPStatus.CONFLICT, {"error": "single_attempt_consumed"})
            return
        if abs(int(time.time()) - timestamp_int) > MAX_CLOCK_SKEW_SECONDS:
            self.respond(HTTPStatus.UNAUTHORIZED, {"error": "expired_request"})
            return
        base = "\n".join(["POST", expected_path, job_id, tenant_id, destination_id, supplied_hash, str(content_length_int), timestamp])
        expected_signature = hmac.new(POLICY.secret, base.encode("utf-8"), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(signature, expected_signature):
            self.respond(HTTPStatus.UNAUTHORIZED, {"error": "invalid_signature"})
            return

        fd, temporary_name = tempfile.mkstemp(prefix="artifact-", suffix=".dcm", dir=ROOT)
        artifact = Path(temporary_name)
        received_hash = hashlib.sha256()
        received = 0
        try:
            with os.fdopen(fd, "wb") as output:
                while received < content_length_int:
                    chunk = self.rfile.read(min(65536, content_length_int - received))
                    if not chunk:
                        self.respond(HTTPStatus.BAD_REQUEST, {"error": "truncated_body"})
                        return
                    output.write(chunk)
                    received_hash.update(chunk)
                    received += len(chunk)
            os.chmod(artifact, 0o600)
            actual_hash = received_hash.hexdigest()
            if received != content_length_int or not hmac.compare_digest(actual_hash, supplied_hash):
                self.respond(HTTPStatus.BAD_REQUEST, {"error": "integrity_check_failed"})
                return

            write_state(job_id_int, {"state": "attempted", "job_id": job_id_int, "tenant_id": tenant_id_int, "destination_id": destination_id_int, "mode": POLICY.mode, "at": int(time.time()), "sha256": actual_hash})
            success, outcome = invoke_dicom_scu(artifact)
            if not success:
                LOG.warning("event=delivery_failed job_id=%s sha256_16=%s stage=%s", job_id_int, actual_hash[:16], outcome)
                self.respond(HTTPStatus.BAD_GATEWAY, {"error": outcome})
                return
            write_state(job_id_int, {"state": "delivered", "job_id": job_id_int, "tenant_id": tenant_id_int, "destination_id": destination_id_int, "mode": POLICY.mode, "at": int(time.time()), "sha256": actual_hash})
            reference = f"gateway-cstore:{actual_hash[:16]}"
            LOG.info("event=delivery_completed job_id=%s sha256_16=%s", job_id_int, actual_hash[:16])
            self.respond(HTTPStatus.CREATED, {"reference": reference})
        finally:
            try:
                artifact.unlink(missing_ok=True)
            except OSError:
                LOG.error("event=temporary_cleanup_failed job_id=%s", POLICY.job_id)


def main() -> NoReturn:
    context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.verify_mode = ssl.CERT_REQUIRED
    context.load_verify_locations(cafile=POLICY.ca_file)
    context.load_cert_chain(certfile=POLICY.server_cert, keyfile=POLICY.server_key)
    server = HTTPServer((POLICY.bind_ip, POLICY.bind_port), Handler)
    server.request_queue_size = 2
    server.socket = context.wrap_socket(server.socket, server_side=True)
    LOG.info("event=bridge_started mode=%s bind=%s:%s", POLICY.mode, POLICY.bind_ip, POLICY.bind_port)
    server.serve_forever(poll_interval=0.5)


if __name__ == "__main__":
    main()
