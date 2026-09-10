"""Bridge privada root-only para Philips Folder por SFTP com fallback SMB.

O PACS nunca acessa SFTP, SMB, WireGuard ou credenciais remotas. Este listener
recebe um artefato autenticado por mTLS + HMAC, faz staging local e somente a
bridge o transfere a um peer IPv4 privado definido pela política root-only.
Nenhuma configuração neste arquivo inicia a bridge ou habilita a feature do PACS.
"""
from __future__ import annotations

import base64
import hashlib
import hmac
import ipaddress
import json
import logging
import os
import re
import secrets
import shutil
import ssl
import stat
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager
from http import HTTPStatus
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path
from typing import NoReturn

from cryptography.hazmat.primitives import hashes
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey, X25519PublicKey

MAX_BYTES = 50 * 1024 * 1024
MAX_CLOCK_SKEW_SECONDS = 60
STATE_ROOT = Path("/var/lib/voxelpacs/philips-folder-bridge")
TARGET_ROOT = Path("/var/lib/voxelpacs/philips-folder-target")
TRANSIENT_TRANSPORT_FAILURES = {"connectivity", "timeout"}
SAFE_REMOTE_PATH = re.compile(r"^/[A-Za-z0-9._/-]{1,180}$")
SAFE_SMB_PATH = re.compile(r"^/?[A-Za-z0-9._/-]{0,160}$")
SAFE_USERNAME = re.compile(r"^[A-Za-z0-9._-]{1,64}$")
SAFE_SMB_USERNAME = re.compile(r"^(?:[A-Za-z0-9._-]{1,64}\\)?[A-Za-z0-9._-]{1,64}$")
SAFE_SHARE = re.compile(r"^[A-Za-z0-9.$_-]{1,80}$")


def setting(name: str) -> str:
    value = os.environ.get(name, "").strip()
    if not value:
        raise RuntimeError(f"missing_required_setting:{name}")
    return value


def private_ipv4(value: str) -> str:
    try:
        address = ipaddress.ip_address(value)
    except ValueError as error:
        raise RuntimeError("invalid_private_peer") from error
    if address.version != 4 or not address.is_private:
        raise RuntimeError("invalid_private_peer")
    return str(address)


def root_only_regular_file(value: str) -> Path:
    path = Path(value)
    try:
        metadata = path.lstat()
    except OSError as error:
        raise RuntimeError("protected_file_unavailable") from error
    if metadata.st_uid != 0 or not stat.S_ISREG(metadata.st_mode) or stat.S_ISLNK(metadata.st_mode) or metadata.st_mode & 0o077:
        raise RuntimeError("invalid_protected_file")
    return path


class BridgeTransferError(RuntimeError):
    def __init__(self, category: str) -> None:
        super().__init__(category)
        self.category = category


class Policy:
    def __init__(self) -> None:
        self.bind_ip = setting("PHILIPS_FOLDER_BIND_IP")
        self.bind_port = int(setting("PHILIPS_FOLDER_BIND_PORT"))
        self.destination_id = int(setting("PHILIPS_FOLDER_DESTINATION_ID"))
        self.mode = setting("PHILIPS_FOLDER_MODE")
        self.allowed_job_id = int(os.environ.get("PHILIPS_FOLDER_ALLOW_JOB_ID", "0"))
        self.target_directory = Path(setting("PHILIPS_FOLDER_TARGET_DIRECTORY"))
        self.secret = root_only_regular_file(setting("PHILIPS_FOLDER_HMAC_FILE")).read_text(encoding="utf-8").strip().encode("utf-8")
        self.ca_file = str(root_only_regular_file(setting("PHILIPS_FOLDER_CLIENT_CA_FILE")))
        self.server_cert = str(root_only_regular_file(setting("PHILIPS_FOLDER_SERVER_CERT_FILE")))
        self.server_key = str(root_only_regular_file(setting("PHILIPS_FOLDER_SERVER_KEY_FILE")))
        self.vpn_peer_host = private_ipv4(setting("PHILIPS_FOLDER_VPN_PEER_HOST"))
        self.transport = setting("PHILIPS_FOLDER_TRANSPORT").lower()
        self.fallback = os.environ.get("PHILIPS_FOLDER_FALLBACK", "").strip().lower()
        if self.mode not in {"single_test", "destination"} or self.destination_id <= 0:
            raise RuntimeError("invalid_bridge_policy")
        if self.mode == "single_test" and self.allowed_job_id <= 0:
            raise RuntimeError("single_test_requires_job")
        if self.transport not in {"sftp", "smb"} or self.fallback not in {"", "smb"}:
            raise RuntimeError("invalid_transport_policy")
        if self.transport != "sftp" and self.fallback:
            raise RuntimeError("invalid_transport_fallback")
        try:
            target_resolved = self.target_directory.resolve(strict=True)
            target_resolved.relative_to(TARGET_ROOT)
        except (OSError, ValueError):
            raise RuntimeError("invalid_bridge_target") from None
        self.target_directory = target_resolved
        if not self.secret or not self.target_directory.is_dir() or self.target_directory.is_symlink():
            raise RuntimeError("invalid_bridge_target")
        self.sftp = self._sftp_settings() if self.transport == "sftp" else None
        self.envelope_private_key = self._envelope_private_key()
        self.smb = self._smb_settings() if self.transport == "smb" or self.fallback == "smb" else None

    def _envelope_private_key(self) -> X25519PrivateKey | None:
        value = os.environ.get("PHILIPS_NON_DICOM_ENVELOPE_PRIVATE_KEY_FILE", "").strip()
        if not value:
            return None
        encoded = root_only_regular_file(value).read_text(encoding="utf-8").strip()
        try:
            raw = base64.b64decode(encoded, validate=True)
            return X25519PrivateKey.from_private_bytes(raw)
        except (ValueError, TypeError):
            raise RuntimeError("invalid_envelope_private_key") from None

    def _sftp_settings(self) -> dict[str, object]:
        host = private_ipv4(setting("PHILIPS_SFTP_HOST"))
        port = int(setting("PHILIPS_SFTP_PORT"))
        username = setting("PHILIPS_SFTP_USER")
        remote_path = setting("PHILIPS_SFTP_REMOTE_PATH")
        if host != self.vpn_peer_host or not 1 <= port <= 65535 or not SAFE_USERNAME.fullmatch(username) or not SAFE_REMOTE_PATH.fullmatch(remote_path):
            raise RuntimeError("invalid_sftp_policy")
        return {
            "host": host,
            "port": port,
            "username": username,
            "remote_path": remote_path.rstrip("/"),
            "private_key": root_only_regular_file(setting("PHILIPS_SFTP_PRIVATE_KEY")),
            "known_hosts": root_only_regular_file(setting("PHILIPS_SFTP_KNOWN_HOSTS")),
        }

    def _smb_settings(self) -> dict[str, object]:
        host = private_ipv4(setting("PHILIPS_SMB_HOST"))
        share = setting("PHILIPS_SMB_SHARE")
        remote_path = os.environ.get("PHILIPS_SMB_REMOTE_PATH", "/").strip()
        username = setting("PHILIPS_SMB_USER")
        if host != self.vpn_peer_host or not SAFE_SHARE.fullmatch(share) or not SAFE_SMB_PATH.fullmatch(remote_path) or not SAFE_SMB_USERNAME.fullmatch(username):
            raise RuntimeError("invalid_smb_policy")
        credentials = os.environ.get("PHILIPS_SMB_CREDENTIALS_FILE", "").strip()
        return {
            "host": host,
            "share": share,
            "remote_path": remote_path.strip("/"),
            "credentials": root_only_regular_file(credentials) if credentials else None,
        }


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


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        for chunk in iter(lambda: source.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def classify_transport_error(output: str, timeout: bool = False) -> str:
    if timeout:
        return "timeout"
    value = output.lower()
    if "host key verification failed" in value or "host identification has changed" in value:
        return "host_key"
    if "publickey" in value or "authentication" in value or "login incorrect" in value:
        return "authentication"
    if "permission denied" in value or "access denied" in value:
        return "permission"
    if any(marker in value for marker in ("connection refused", "no route to host", "network is unreachable", "connection reset")):
        return "connectivity"
    if "timed out" in value or "timeout" in value:
        return "timeout"
    return "remote_io"


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
        test_prefix = "/v1/philips-folder/smb-test/"
        if self.path.startswith(test_prefix) and self.path[len(test_prefix):].isdigit():
            self.test_smb_connectivity(int(self.path[len(test_prefix):]))
            return
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
            and re.fullmatch(r"[a-f0-9]{64}", supplied_hash) is not None
            and (POLICY.mode == "destination" or job_id == POLICY.allowed_job_id)
        )
        if not permitted:
            self.respond(HTTPStatus.FORBIDDEN, {"error": "policy_rejected"})
            return
        if abs(int(time.time()) - request_time) > MAX_CLOCK_SKEW_SECONDS:
            self.respond(HTTPStatus.UNAUTHORIZED, {"error": "expired_request"})
            return
        envelope = self.headers.get("X-VOXEL-Secret-Envelope", "")
        envelope_hash = hashlib.sha256(envelope.encode("utf-8")).hexdigest() if envelope else ""
        signature_parts = ["POST", self.path, str(job_id), destination_id, filename, supplied_hash, str(length), timestamp]
        if envelope:
            signature_parts.append(envelope_hash)
        signature_base = "\n".join(signature_parts)
        expected = hmac.new(POLICY.secret, signature_base.encode("utf-8"), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(signature, expected):
            self.respond(HTTPStatus.UNAUTHORIZED, {"error": "invalid_signature"})
            return
        if envelope and POLICY.envelope_private_key is None:
            self.respond(HTTPStatus.FORBIDDEN, {"error": "policy_rejected"})
            return
        previous = read_state(job_id)
        if previous.get("sha256") == supplied_hash and previous.get("state") == "delivered":
            self.respond(HTTPStatus.CREATED, {"reference": previous["reference"], "sha256": supplied_hash})
            return
        if previous:
            self.respond(HTTPStatus.CONFLICT, {"error": "job_state_conflict"})
            return
        staged = self.receive_or_reuse_stage(filename, supplied_hash, length)
        if staged is None:
            return
        try:
            with self.temporary_smb_credentials(envelope, destination_id) as credentials:
                transport = self.deliver_remote(job_id, filename, staged, supplied_hash, length, credentials)
        except BridgeTransferError as error:
            LOG.warning("event=philips_export_failed job_id=%s transport=%s reason_category=%s", job_id, POLICY.transport, error.category)
            self.respond(HTTPStatus.BAD_GATEWAY, {"error": "gateway_delivery_failed", "reason_category": error.category})
            return
        reference = f"gateway-philips-folder:{supplied_hash[:16]}"
        write_state(job_id, {"state": "delivered", "sha256": supplied_hash, "reference": reference, "transport": transport})
        LOG.info("event=philips_export_success job_id=%s transport=%s sha256_16=%s", job_id, transport, supplied_hash[:16])
        self.respond(HTTPStatus.CREATED, {"reference": reference, "sha256": supplied_hash})

    def test_smb_connectivity(self, destination_id: int) -> None:
        tenant_id = self.headers.get("X-VOXEL-Tenant-ID", "")
        timestamp = self.headers.get("X-VOXEL-Timestamp", "")
        signature = self.headers.get("X-VOXEL-Signature", "")
        envelope = self.headers.get("X-VOXEL-Secret-Envelope", "")
        supplied_configuration_hash = self.headers.get("X-VOXEL-Configuration-SHA256", "")
        try:
            request_time = int(timestamp)
            tenant_value = int(tenant_id)
        except ValueError:
            self.respond(HTTPStatus.BAD_REQUEST, {"error": "invalid_headers"})
            return
        if tenant_value <= 0 or destination_id != POLICY.destination_id or not envelope or POLICY.envelope_private_key is None:
            self.respond(HTTPStatus.FORBIDDEN, {"error": "policy_rejected"})
            return
        if abs(int(time.time()) - request_time) > MAX_CLOCK_SKEW_SECONDS:
            self.respond(HTTPStatus.UNAUTHORIZED, {"error": "expired_request"})
            return
        expected_configuration_hash = self.smb_configuration_hash()
        if not hmac.compare_digest(supplied_configuration_hash, expected_configuration_hash):
            self.respond(HTTPStatus.FORBIDDEN, {"error": "policy_rejected"})
            return
        envelope_hash = hashlib.sha256(envelope.encode("utf-8")).hexdigest()
        signature_base = "\n".join(["POST", self.path, tenant_id, str(destination_id), supplied_configuration_hash, envelope_hash, timestamp])
        expected = hmac.new(POLICY.secret, signature_base.encode("utf-8"), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(signature, expected):
            self.respond(HTTPStatus.UNAUTHORIZED, {"error": "invalid_signature"})
            return
        try:
            with self.temporary_smb_credentials(envelope, destination_id, tenant_value) as credentials:
                self.smb_write_probe(credentials)
        except BridgeTransferError as error:
            LOG.warning("event=philips_smb_test_failed destination_id=%s reason_category=%s", destination_id, error.category)
            self.respond(HTTPStatus.BAD_GATEWAY, {"error": "gateway_smb_test_failed", "reason_category": error.category})
            return
        LOG.info("event=philips_smb_test_success destination_id=%s", destination_id)
        self.respond(HTTPStatus.OK, {"status": "ok"})

    def smb_configuration_hash(self) -> str:
        if not isinstance(POLICY.smb, dict):
            return ""
        value = {
            "host": str(POLICY.smb["host"]),
            "port": 445,
            "share": str(POLICY.smb["share"]),
            "username": str(POLICY.smb["username"]),
        }
        return hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()

    @contextmanager
    def temporary_smb_credentials(self, envelope: str, destination_id: int, tenant_id: int | None = None):
        credential_file: Path | None = None
        password = ""
        try:
            if not envelope:
                if not isinstance(POLICY.smb, dict) or not isinstance(POLICY.smb.get("credentials"), Path):
                    raise BridgeTransferError("credentials_unavailable")
                yield POLICY.smb["credentials"]
                return
            password = self.open_secret_envelope(envelope, destination_id, tenant_id)
            username = str(POLICY.smb["username"]) if isinstance(POLICY.smb, dict) else ""
            account, domain = (username.split("\\", 1)[::-1] if "\\" in username else (username, ""))
            descriptor, raw_path = tempfile.mkstemp(prefix="smb-credentials-", dir=STATE_ROOT)
            credential_file = Path(raw_path)
            with os.fdopen(descriptor, "w", encoding="utf-8") as output:
                output.write("username=" + account + "\npassword=" + password + "\n")
                if domain:
                    output.write("domain=" + domain + "\n")
                output.flush()
                os.fsync(output.fileno())
            os.chmod(credential_file, 0o600)
            yield credential_file
        finally:
            password = ""
            if credential_file is not None:
                credential_file.unlink(missing_ok=True)

    def open_secret_envelope(self, envelope: str, destination_id: int, tenant_id: int | None) -> str:
        try:
            decoded = json.loads(base64.b64decode(envelope, validate=True).decode("utf-8"))
            if not isinstance(decoded, dict) or decoded.get("v") != 1 or int(decoded.get("destination_id", 0)) != destination_id:
                raise ValueError
            if tenant_id is not None and int(decoded.get("tenant_id", 0)) != tenant_id:
                raise ValueError
            expires_at = int(decoded.get("expires_at", 0))
            if expires_at < int(time.time()) or expires_at > int(time.time()) + 90 or POLICY.envelope_private_key is None:
                raise ValueError
            ephemeral = X25519PublicKey.from_public_bytes(base64.b64decode(str(decoded["ephemeral_public"]), validate=True))
            shared = POLICY.envelope_private_key.exchange(ephemeral)
            context = "voxel-nondicom-smb-v1|" + str(decoded["tenant_id"]) + "|" + str(destination_id) + "|" + str(expires_at)
            key = HKDF(algorithm=hashes.SHA256(), length=32, salt=None, info=context.encode("utf-8")).derive(shared)
            plaintext = AESGCM(key).decrypt(base64.b64decode(str(decoded["iv"]), validate=True), base64.b64decode(str(decoded["ciphertext"]), validate=True) + base64.b64decode(str(decoded["tag"]), validate=True), context.encode("utf-8"))
            password = plaintext.decode("utf-8")
            if not password or len(password) > 512 or "\x00" in password:
                raise ValueError
            return password
        except (KeyError, TypeError, ValueError, UnicodeDecodeError):
            raise BridgeTransferError("credentials_unavailable") from None

    def receive_or_reuse_stage(self, filename: str, expected_hash: str, length: int) -> Path | None:
        final_path = POLICY.target_directory / filename
        if final_path.exists() or final_path.is_symlink():
            if final_path.is_file() and not final_path.is_symlink() and final_path.stat().st_size == length and hmac.compare_digest(sha256_file(final_path), expected_hash):
                return final_path
            self.respond(HTTPStatus.CONFLICT, {"error": "staging_conflict"})
            return None
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
                        return None
                    output.write(chunk)
                    digest.update(chunk)
                    received += len(chunk)
                output.flush()
                os.fsync(output.fileno())
            os.chmod(temporary, 0o600)
            actual_hash = digest.hexdigest()
            if received != length or not hmac.compare_digest(actual_hash, expected_hash):
                self.respond(HTTPStatus.BAD_REQUEST, {"error": "integrity_check_failed"})
                return None
            os.replace(temporary, final_path)
            temporary = None
            return final_path
        except OSError:
            self.respond(HTTPStatus.BAD_GATEWAY, {"error": "staging_write_failed"})
            return None
        finally:
            if temporary is not None:
                temporary.unlink(missing_ok=True)

    def deliver_remote(self, job_id: int, filename: str, staged: Path, expected_hash: str, length: int, credentials: Path | None) -> str:
        try:
            if POLICY.transport == "sftp":
                self.transfer_sftp(job_id, filename, staged, length)
                return "sftp"
            self.transfer_smb(job_id, filename, staged, expected_hash, length, credentials)
            return "smb"
        except BridgeTransferError as primary_error:
            if POLICY.transport == "sftp" and POLICY.fallback == "smb" and primary_error.category in TRANSIENT_TRANSPORT_FAILURES:
                LOG.warning("event=philips_smb_fallback_attempt job_id=%s reason_category=%s", job_id, primary_error.category)
                self.transfer_smb(job_id, filename, staged, expected_hash, length, credentials)
                return "smb"
            raise

    @staticmethod
    def _sftp_remote_path(filename: str) -> tuple[str, str]:
        if not isinstance(POLICY.sftp, dict):
            raise BridgeTransferError("configuration")
        directory = str(POLICY.sftp["remote_path"])
        final_path = f"{directory}/{filename}"
        temporary_path = f"{directory}/.voxel-{secrets.token_hex(12)}.part"
        return final_path, temporary_path

    @staticmethod
    def _sftp_command(batch: str) -> subprocess.CompletedProcess[str]:
        if not isinstance(POLICY.sftp, dict) or not shutil.which("sftp"):
            raise BridgeTransferError("configuration")
        command = [
            "sftp",
            "-F", "/dev/null",
            "-oBatchMode=yes",
            "-oStrictHostKeyChecking=yes",
            "-oIdentitiesOnly=yes",
            "-oUserKnownHostsFile=" + str(POLICY.sftp["known_hosts"]),
            "-oIdentityFile=" + str(POLICY.sftp["private_key"]),
            "-P", str(POLICY.sftp["port"]),
            str(POLICY.sftp["username"]) + "@" + str(POLICY.sftp["host"]),
        ]
        try:
            return subprocess.run(command, input=batch, capture_output=True, text=True, timeout=45, check=False)
        except subprocess.TimeoutExpired as error:
            raise BridgeTransferError("timeout") from error
        except OSError as error:
            raise BridgeTransferError("configuration") from error

    @staticmethod
    def _sftp_missing(result: subprocess.CompletedProcess[str]) -> bool:
        output = (result.stdout + result.stderr).lower()
        return any(marker in output for marker in ("no such file", "couldn't stat", "not found"))

    @staticmethod
    def _sftp_size_matches(result: subprocess.CompletedProcess[str], expected_size: int) -> bool:
        output = result.stdout + result.stderr
        return bool(re.search(r"\s" + re.escape(str(expected_size)) + r"\s+[A-Z][a-z]{2}\s", output))

    def sftp_remote_matches(self, remote_path: str, expected_hash: str, expected_size: int) -> bool:
        descriptor, raw_path = tempfile.mkstemp(prefix="sftp-verify-", suffix=".part", dir=STATE_ROOT)
        os.close(descriptor)
        downloaded = Path(raw_path)
        downloaded.unlink(missing_ok=True)
        try:
            result = self._sftp_command(f"get {remote_path} {downloaded}\n")
            if result.returncode != 0:
                raise BridgeTransferError(classify_transport_error(result.stdout + result.stderr))
            return downloaded.is_file() and downloaded.stat().st_size == expected_size and hmac.compare_digest(sha256_file(downloaded), expected_hash)
        finally:
            downloaded.unlink(missing_ok=True)

    def transfer_sftp(self, job_id: int, filename: str, staged: Path, expected_hash: str, length: int) -> None:
        final_path, temporary_path = self._sftp_remote_path(filename)
        existing = self._sftp_command(f"ls -ln {final_path}\n")
        if existing.returncode == 0:
            if self.sftp_remote_matches(final_path, expected_hash, length):
                LOG.info("event=philips_sftp_success job_id=%s", job_id)
                return
            raise BridgeTransferError("remote_io")
        if not self._sftp_missing(existing):
            raise BridgeTransferError(classify_transport_error(existing.stdout + existing.stderr))
        uploaded = self._sftp_command(f"put {staged} {temporary_path}\nls -ln {temporary_path}\n")
        if uploaded.returncode != 0 or not self._sftp_size_matches(uploaded, length):
            raise BridgeTransferError(classify_transport_error(uploaded.stdout + uploaded.stderr))
        renamed = self._sftp_command(f"rename {temporary_path} {final_path}\n")
        if renamed.returncode != 0:
            raise BridgeTransferError(classify_transport_error(renamed.stdout + renamed.stderr))
        if not self.sftp_remote_matches(final_path, expected_hash, length):
            raise BridgeTransferError("remote_io")
        LOG.info("event=philips_sftp_success job_id=%s", job_id)

    def _smb_command(self, credentials: Path, command: str) -> subprocess.CompletedProcess[str]:
        if not isinstance(POLICY.smb, dict) or not shutil.which("smbclient"):
            raise BridgeTransferError("configuration")
        source = "//" + str(POLICY.smb["host"]) + "/" + str(POLICY.smb["share"])
        try:
            return subprocess.run([
                "smbclient", source, "-A", str(credentials), "-m", "SMB3",
                "--option=client min protocol=SMB3", "--option=client max protocol=SMB3",
                "-c", command,
            ], capture_output=True, text=True, timeout=45, check=False)
        except subprocess.TimeoutExpired as error:
            raise BridgeTransferError("timeout") from error
        except OSError as error:
            raise BridgeTransferError("configuration") from error

    @staticmethod
    def _smb_missing(result: subprocess.CompletedProcess[str]) -> bool:
        output = (result.stdout + result.stderr).lower()
        return any(marker in output for marker in ("nt_status_no_such_file", "not found", "no such file"))

    def _smb_remote_path(self, filename: str) -> tuple[str, str]:
        if not isinstance(POLICY.smb, dict):
            raise BridgeTransferError("configuration")
        directory = str(POLICY.smb["remote_path"])
        return f"{directory}/{filename}", f"{directory}/.voxel-{secrets.token_hex(12)}.part"

    def smb_remote_matches(self, credentials: Path, remote_path: str, expected_hash: str, expected_size: int) -> bool:
        descriptor, raw_path = tempfile.mkstemp(prefix="smb-verify-", suffix=".part", dir=STATE_ROOT)
        os.close(descriptor)
        downloaded = Path(raw_path)
        downloaded.unlink(missing_ok=True)
        try:
            result = self._smb_command(credentials, f"get {remote_path} {downloaded}")
            if result.returncode != 0:
                raise BridgeTransferError(classify_transport_error(result.stdout + result.stderr))
            return downloaded.is_file() and downloaded.stat().st_size == expected_size and hmac.compare_digest(sha256_file(downloaded), expected_hash)
        finally:
            downloaded.unlink(missing_ok=True)

    def smb_write_probe(self, credentials: Path) -> None:
        descriptor, raw_path = tempfile.mkstemp(prefix="smb-probe-", suffix=".tmp", dir=STATE_ROOT)
        probe = Path(raw_path)
        remote = ""
        try:
            with os.fdopen(descriptor, "wb") as output:
                output.write(b"VOXEL_SMB_CONNECTIVITY_PROBE\n")
                output.flush()
                os.fsync(output.fileno())
            remote = str(POLICY.smb["remote_path"]) + "/.voxel-probe-" + secrets.token_hex(12) + ".tmp"
            result = self._smb_command(credentials, f"put {probe} {remote}; del {remote}")
            if result.returncode != 0:
                raise BridgeTransferError(classify_transport_error(result.stdout + result.stderr))
        finally:
            if remote:
                try:
                    self._smb_command(credentials, f"del {remote}")
                except BridgeTransferError:
                    pass
            probe.unlink(missing_ok=True)

    def transfer_smb(self, job_id: int, filename: str, staged: Path, expected_hash: str, length: int, credentials: Path | None) -> None:
        if credentials is None:
            raise BridgeTransferError("credentials_unavailable")
        final_path, temporary_path = self._smb_remote_path(filename)
        existing = self._smb_command(credentials, f"ls {final_path}")
        if existing.returncode == 0:
            if self.smb_remote_matches(credentials, final_path, expected_hash, length):
                LOG.info("event=philips_smb_success job_id=%s", job_id)
                return
            raise BridgeTransferError("remote_io")
        if not self._smb_missing(existing):
            raise BridgeTransferError(classify_transport_error(existing.stdout + existing.stderr))
        uploaded = self._smb_command(credentials, f"put {staged} {temporary_path}; rename {temporary_path} {final_path}")
        if uploaded.returncode != 0:
            raise BridgeTransferError(classify_transport_error(uploaded.stdout + uploaded.stderr))
        if not self.smb_remote_matches(credentials, final_path, expected_hash, length):
            raise BridgeTransferError("remote_io")
        LOG.info("event=philips_smb_success job_id=%s", job_id)

    @staticmethod
    def valid_filename(value: str) -> bool:
        return re.fullmatch(r"VOXEL_[A-Za-z0-9._-]{1,160}\.(?:pdf|xml)", value) is not None


def main() -> NoReturn:
    context = ssl.create_default_context(ssl.Purpose.CLIENT_AUTH)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.verify_mode = ssl.CERT_REQUIRED
    context.load_verify_locations(cafile=POLICY.ca_file)
    context.load_cert_chain(certfile=POLICY.server_cert, keyfile=POLICY.server_key)
    server = HTTPServer((POLICY.bind_ip, POLICY.bind_port), Handler)
    server.request_queue_size = 2
    server.socket = context.wrap_socket(server.socket, server_side=True)
    LOG.info("event=philips_folder_bridge_started destination_id=%s mode=%s transport=%s", POLICY.destination_id, POLICY.mode, POLICY.transport)
    server.serve_forever(poll_interval=0.5)


if __name__ == "__main__":
    main()
