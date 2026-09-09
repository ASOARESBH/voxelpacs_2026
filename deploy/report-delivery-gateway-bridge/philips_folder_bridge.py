"""Bridge privada, root-owned e PDF-only para entrega Philips Folder.

O PACS nunca acessa compartilhamentos SMB/SFTP. Este listener HTTPS recebe um
único PDF autenticado por mTLS + HMAC e o grava atomically na pasta local que
somente o gateway conhece. O serviço permanece inerte até receber configuração
root-owned e não inicia automaticamente por este arquivo.
"""
from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
import re
import ssl
import sys
import tempfile
import time
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import NoReturn

MAX_BYTES = 50 * 1024 * 1024
MAX_CLOCK_SKEW_SECONDS = 60
STATE_ROOT = Path("/var/lib/voxelpacs/philips-folder-bridge")
TARGET_ROOT = Path("/var/lib/voxelpacs/philips-folder-target")


def setting(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"missing_required_setting:{name}")
    return value


class Policy:
    def __init__(self) -> None:
        self.bind_ip = setting("PHILIPS_FOLDER_BIND_IP")
        self.bind_port = int(setting("PHILIPS_FOLDER_BIND_PORT"))
        self.destination_id = int(setting("PHILIPS_FOLDER_DESTINATION_ID"))
        self.mode = setting("PHILIPS_FOLDER_MODE")
        self.allowed_job_id = int(os.environ.get("PHILIPS_FOLDER_ALLOW_JOB_ID", "0"))
        self.target_directory = Path(setting("PHILIPS_FOLDER_TARGET_DIRECTORY"))
        self.secret = Path(setting("PHILIPS_FOLDER_HMAC_FILE")).read_text(encoding="utf-8").strip().encode("utf-8")
        self.ca_file = setting("PHILIPS_FOLDER_CLIENT_CA_FILE")
        self.server_cert = setting("PHILIPS_FOLDER_SERVER_CERT_FILE")
        self.server_key = setting("PHILIPS_FOLDER_SERVER_KEY_FILE")
        if self.mode not in {"single_test", "destination"} or self.destination_id <= 0:
            raise RuntimeError("invalid_bridge_policy")
        if self.mode == "single_test" and self.allowed_job_id <= 0:
            raise RuntimeError("single_test_requires_job")
        try:
            target_resolved = self.target_directory.resolve(strict=True)
            target_resolved.relative_to(TARGET_ROOT)
        except (OSError, ValueError):
            raise RuntimeError("invalid_bridge_target") from None
        self.target_directory = target_resolved
        if not self.secret or not self.target_directory.is_dir() or self.target_directory.is_symlink():
            raise RuntimeError("invalid_bridge_target")


POLICY = Policy()
STATE_ROOT.mkdir(mode=0o700, parents=True, exist_ok=True)
os.chmod(STATE_ROOT, 0o700)
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s", stream=sys.stdout)
LOG = logging.getLogger("philips_folder_bridge")


def state_file(job_id: int) -> Path:
    return STATE_ROOT / f"job-{job_id}.json"


def read_state(job_id: int) -> dict[str, str]:
    try:
        payload = json.loads(state_file(job_id).read_text(encoding="utf-8"))
        return payload if isinstance(payload, dict) else {}
    except (OSError, json.JSONDecodeError):
        return {}


def write_state(job_id: int, value: dict[str, str]) -> None:
    target = state_file(job_id)
    temporary = target.with_suffix(".tmp")
    temporary.write_text(json.dumps(value, sort_keys=True), encoding="utf-8")
    os.chmod(temporary, 0o600)
    os.replace(temporary, target)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "VOXEL-Philips-Folder-Bridge"

    def log_message(self, _format: str, *_args: object) -> None:
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
        prefix = "/v1/philips-folder/"
        if not self.path.startswith(prefix) or not self.path[len(prefix):].isdigit():
            self.respond(HTTPStatus.NOT_FOUND, {"error": "not_found"})
            return
        job_id = int(self.path[len(prefix):])
        supplied_job_id = self.headers.get("X-VOXEL-Job-ID", "")
        destination_id = self.headers.get("X-VOXEL-Destination-ID", "")
        filename = self.headers.get("X-VOXEL-Filename", "")
        timestamp = self.headers.get("X-VOXEL-Timestamp", "")
        supplied_hash = self.headers.get("X-VOXEL-SHA256", "").lower()
        signature = self.headers.get("X-VOXEL-Signature", "")
        content_length = self.headers.get("Content-Length", "")
        try:
            length = int(content_length)
            request_time = int(timestamp)
        except ValueError:
            self.respond(HTTPStatus.BAD_REQUEST, {"error": "invalid_headers"})
            return
        permitted = (
            supplied_job_id == str(job_id)
            and destination_id == str(POLICY.destination_id)
            and 256 <= length <= MAX_BYTES
            and self.valid_filename(filename)
            and (POLICY.mode == "destination" or job_id == POLICY.allowed_job_id)
        )
        if not permitted:
            self.respond(HTTPStatus.FORBIDDEN, {"error": "policy_rejected"})
            return
        if abs(int(time.time()) - request_time) > MAX_CLOCK_SKEW_SECONDS:
            self.respond(HTTPStatus.UNAUTHORIZED, {"error": "expired_request"})
            return
        signature_base = "\n".join(["POST", self.path, str(job_id), destination_id, filename, supplied_hash, str(length), timestamp])
        expected = hmac.new(POLICY.secret, signature_base.encode("utf-8"), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(signature, expected):
            self.respond(HTTPStatus.UNAUTHORIZED, {"error": "invalid_signature"})
            return
        previous = read_state(job_id)
        if previous.get("sha256") == supplied_hash and previous.get("state") == "delivered":
            self.respond(HTTPStatus.CREATED, {"reference": previous["reference"], "sha256": supplied_hash})
            return
        if previous:
            self.respond(HTTPStatus.CONFLICT, {"error": "job_state_conflict"})
            return
        self.receive_atomically(job_id, filename, supplied_hash, length)

    def receive_atomically(self, job_id: int, filename: str, expected_hash: str, length: int) -> None:
        final_path = POLICY.target_directory / filename
        if final_path.exists() or final_path.is_symlink():
            self.respond(HTTPStatus.CONFLICT, {"error": "remote_name_conflict"})
            return
        received = 0
        digest = hashlib.sha256()
        temporary: Path | None = None
        try:
            descriptor, raw_path = tempfile.mkstemp(prefix=".voxel-", suffix=".part", dir=POLICY.target_directory)
            temporary = Path(raw_path)
            with os.fdopen(descriptor, "wb") as output:
                while received < length:
                    chunk = self.rfile.read(min(65536, length - received))
                    if not chunk:
                        self.respond(HTTPStatus.BAD_REQUEST, {"error": "truncated_body"})
                        return
                    output.write(chunk)
                    digest.update(chunk)
                    received += len(chunk)
                output.flush()
                os.fsync(output.fileno())
            os.chmod(temporary, 0o600)
            actual_hash = digest.hexdigest()
            if received != length or not hmac.compare_digest(actual_hash, expected_hash):
                self.respond(HTTPStatus.BAD_REQUEST, {"error": "integrity_check_failed"})
                return
            os.replace(temporary, final_path)
            temporary = None
            with final_path.open("rb") as final_input:
                final_hash = hashlib.file_digest(final_input, "sha256").hexdigest()
            if not hmac.compare_digest(final_hash, expected_hash):
                final_path.unlink(missing_ok=True)
                self.respond(HTTPStatus.BAD_GATEWAY, {"error": "remote_integrity_failed"})
                return
            reference = f"gateway-philips-folder:{expected_hash[:16]}"
            write_state(job_id, {"state": "delivered", "sha256": expected_hash, "reference": reference})
            LOG.info("event=philips_export_success job_id=%s sha256_16=%s", job_id, expected_hash[:16])
            self.respond(HTTPStatus.CREATED, {"reference": reference, "sha256": expected_hash})
        except OSError:
            LOG.warning("event=philips_export_failed job_id=%s stage=atomic_write", job_id)
            self.respond(HTTPStatus.BAD_GATEWAY, {"error": "folder_write_failed"})
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)

    @staticmethod
    def valid_filename(value: str) -> bool:
        return re.fullmatch(r"VOXEL_[A-Za-z0-9._-]{1,160}\.pdf", value) is not None


def main() -> NoReturn:
    context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.verify_mode = ssl.CERT_REQUIRED
    context.load_verify_locations(cafile=POLICY.ca_file)
    context.load_cert_chain(certfile=POLICY.server_cert, keyfile=POLICY.server_key)
    server = HTTPServer((POLICY.bind_ip, POLICY.bind_port), Handler)
    server.request_queue_size = 2
    server.socket = context.wrap_socket(server.socket, server_side=True)
    LOG.info("event=philips_folder_bridge_started destination_id=%s mode=%s", POLICY.destination_id, POLICY.mode)
    server.serve_forever(poll_interval=0.5)


if __name__ == "__main__":
    main()
