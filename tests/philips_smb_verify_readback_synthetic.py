#!/usr/bin/env python3
"""Synthetic unit checks for the Bridge SMB path and VERIFY helpers.

The test extracts only the real methods under test from the versioned Bridge,
then replaces the subprocess caller with an in-memory synthetic SMB response.
No network, credentials, PDF, XML, Job or production state is used.
"""
from __future__ import annotations

import ast
import hashlib
import secrets
import shlex
import subprocess
import tempfile
from pathlib import Path
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = ROOT / "deploy/report-delivery-gateway-bridge/philips_folder_bridge.py"


class BridgeTransferError(RuntimeError):
    def __init__(self, category: str) -> None:
        super().__init__(category)
        self.category = category


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def classify_transport_error(output: str, timeout: bool = False) -> str:
    if timeout:
        return "timeout"
    if "access" in output.lower():
        return "permission"
    return "remote_io"


def load_real_methods(namespace: dict[str, object]) -> type:
    tree = ast.parse(BRIDGE.read_text(encoding="utf-8"), filename=str(BRIDGE))
    handler = next(node for node in tree.body if isinstance(node, ast.ClassDef) and node.name == "Handler")
    method_names = {"_smb_arg", "_smb_remote_path", "smb_remote_matches"}
    handler.body = [node for node in handler.body if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)) and node.name in method_names]
    handler.bases = []
    module = ast.Module(body=[handler], type_ignores=[])
    ast.fix_missing_locations(module)
    exec(compile(module, str(BRIDGE), "exec"), namespace)
    return namespace["Handler"]  # type: ignore[return-value]


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="voxelpacs-smb-verify-test-") as temp_dir:
        state_root = Path(temp_dir)
        namespace: dict[str, object] = {
            "Path": Path,
            "subprocess": subprocess,
            "hashlib": hashlib,
            "hmac": __import__("hmac"),
            "os": __import__("os"),
            "secrets": secrets,
            "tempfile": tempfile,
            "BridgeTransferError": BridgeTransferError,
            "STATE_ROOT": state_root,
            "sha256_file": sha256_file,
            "classify_transport_error": classify_transport_error,
        }
        Handler = load_real_methods(namespace)
        logs: list[tuple[object, ...]] = []
        Handler._log_smb_stage = staticmethod(lambda *args, **kwargs: logs.append((args, kwargs)))

        payload = b"SYNTHETIC_SMB_VERIFY_PAYLOAD\n"
        commands: list[str] = []
        failure_mode = {"enabled": False}

        def fake_smb_command(self: object, credentials: Path, command: str) -> subprocess.CompletedProcess[str]:
            commands.append(command)
            parts = shlex.split(command)
            if parts[:1] == ["get"] and not failure_mode["enabled"]:
                Path(parts[2]).write_bytes(payload)
                return subprocess.CompletedProcess([], 0, "", "")
            if parts[:1] == ["get"]:
                return subprocess.CompletedProcess([], 1, "", "ACCESS_DENIED")
            raise AssertionError(f"unexpected synthetic command: {command}")

        Handler._smb_command = fake_smb_command
        handler = Handler.__new__(Handler)
        namespace["POLICY"] = SimpleNamespace(smb={"remote_path": ""})

        final_root, temporary_root = handler._smb_remote_path("VOXEL_SYNTHETIC.pdf")
        assert final_root == "VOXEL_SYNTHETIC.pdf"
        assert temporary_root.startswith(".voxel-") and temporary_root.endswith(".part")
        assert "//" not in final_root and "//" not in temporary_root
        assert handler._smb_arg("VOXEL_SYNTHETIC.pdf") == '"VOXEL_SYNTHETIC.pdf"'

        expected_hash = hashlib.sha256(payload).hexdigest()
        assert handler.smb_remote_matches(
            9001,
            Path("synthetic-credential-file"),
            final_root,
            expected_hash,
            len(payload),
            remote_target="temporary",
        ) is True
        assert commands[-1].startswith('get "VOXEL_SYNTHETIC.pdf" "')
        assert not list(state_root.glob("*.part")), "VERIFY temporary must be removed"

        failure_mode["enabled"] = True
        try:
            handler.smb_remote_matches(
                9002,
                Path("synthetic-credential-file"),
                final_root,
                expected_hash,
                len(payload),
                remote_target="final",
            )
        except BridgeTransferError as error:
            assert error.category == "permission"
        else:
            raise AssertionError("nonzero GET must fail closed")
        assert not list(state_root.glob("*.part")), "failed VERIFY temporary must be removed"

        namespace["POLICY"] = SimpleNamespace(smb={"remote_path": "/PDF/"})
        nested_final, nested_temporary = handler._smb_remote_path("VOXEL_SYNTHETIC.pdf")
        assert nested_final == "PDF/VOXEL_SYNTHETIC.pdf"
        assert nested_temporary.startswith("PDF/.voxel-") and nested_temporary.endswith(".part")
        assert "//" not in nested_final and "//" not in nested_temporary

        print("PHILIPS_SMB_VERIFY_SYNTHETIC_OK")
        print("NETWORK=NO")
        print("CLINICAL_DATA=NO")
        print("COMMANDS_CAPTURED=%d" % len(commands))
        print("LOG_EVENTS_CAPTURED=%d" % len(logs))


if __name__ == "__main__":
    main()
