#!/usr/bin/env python3
"""Synthetic end-to-end checks for the Bridge SMB transfer flows.

Only versioned methods are executed. The SMB subprocess is replaced by an
in-memory synthetic share, so this test uses no network, credentials, PDF,
XML, patient data, Job, or production state.
"""
from __future__ import annotations

import ast
import hashlib
import hmac
import secrets
import shlex
import subprocess
import tempfile
import xml.etree.ElementTree as ET
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
    handler = next(
        node for node in tree.body
        if isinstance(node, ast.ClassDef) and node.name == "Handler"
    )
    method_names = {
        "_smb_arg",
        "_smb_remote_path",
        "_smb_missing",
        "smb_remote_matches",
        "_validate_submission_xml",
        "transfer_smb",
        "deliver_submission_package_remote",
    }
    handler.body = [
        node for node in handler.body
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
        and node.name in method_names
    ]
    handler.bases = []
    module = ast.Module(body=[handler], type_ignores=[])
    ast.fix_missing_locations(module)
    exec(compile(module, str(BRIDGE), "exec"), namespace)
    return namespace["Handler"]  # type: ignore[return-value]


def assert_sequence(stages: list[tuple[str, str, str]], expected: list[tuple[str, str]]) -> None:
    actual = [(stage, target) for stage, target, _classification in stages]
    assert actual == expected, f"unexpected stage sequence: {actual!r}"


def synthetic_xml(pdf_filename: str) -> bytes:
    values = {
        "task_patient_id": "SYNTHETIC-PATIENT",
        "task_document_name": "SYNTHETIC-DOCUMENT",
        "task_document_date": "20260101000000",
        "task_image_date": "20260101000000",
        "task_file_path": pdf_filename,
        "task_file_name": pdf_filename,
        "task_accession_number": "SYNTHETIC-ACCESSION",
        "task_document_mimetype": "application/pdf",
        "task_patient_birthday": "20000101",
        "task_patient_gender": "O",
        "task_site_id": "SYNTHETIC-SITE",
        "task_patient_issuer": "SYNTHETIC-ISSUER",
        "task_author_id": "SYNTHETIC-AUTHOR",
        "task_author_humanname_family": "SYNTHETIC-FAMILY",
        "task_author_humanname_given": "SYNTHETIC-GIVEN",
        "task_author_humanname_middle": "",
        "task_modalities": "OT",
        "task_delete_file": "false",
        "task_patient_humanname_family": "SYNTHETIC-PATIENT-FAMILY",
        "task_patient_humanname_given": "SYNTHETIC-PATIENT-GIVEN",
        "task_patient_humanname_middle": "",
    }
    root = ET.Element("submission")
    document = ET.SubElement(root, "document")
    for name, value in values.items():
        node = ET.SubElement(document, name)
        node.text = value
    body = ET.tostring(root, encoding="iso-8859-1", xml_declaration=False)
    return b'<?xml version="1.0" encoding="iso-8859-1"?>' + body


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="voxelpacs-smb-verify-test-") as temp_dir:
        root = Path(temp_dir)
        state_root = root / "state"
        state_root.mkdir()
        namespace: dict[str, object] = {
            "Path": Path,
            "ET": ET,
            "subprocess": subprocess,
            "hashlib": hashlib,
            "hmac": hmac,
            "os": __import__("os"),
            "secrets": secrets,
            "tempfile": tempfile,
            "BridgeTransferError": BridgeTransferError,
            "STATE_ROOT": state_root,
            "sha256_file": sha256_file,
            "classify_transport_error": classify_transport_error,
        }
        Handler = load_real_methods(namespace)
        handler = Handler.__new__(Handler)
        namespace["POLICY"] = SimpleNamespace(smb={"remote_path": ""})
        namespace["LOG"] = SimpleNamespace(info=lambda *_args, **_kwargs: None)

        remote_store: dict[str, bytes] = {}
        commands: list[str] = []
        stages: list[tuple[str, str, str]] = []
        corrupt_final = {"name": None}

        def record_stage(
            _job_id: int,
            stage: str,
            _result: subprocess.CompletedProcess[str] | None = None,
            classification: str = "unknown",
            _remote_size: int | None = None,
            _remote_hash_match: bool | None = None,
            remote_target: str = "unknown",
        ) -> None:
            stages.append((stage, remote_target, classification))

        def fake_smb_command(
            _self: object,
            _credentials: Path,
            command: str,
        ) -> subprocess.CompletedProcess[str]:
            commands.append(command)
            parts = shlex.split(command)
            operation = parts[0]
            if operation == "ls":
                exists = parts[1] in remote_store
                return subprocess.CompletedProcess([], 0 if exists else 1, "", "" if exists else "NT_STATUS_NO_SUCH_FILE")
            if operation == "put":
                remote_store[parts[2]] = Path(parts[1]).read_bytes()
                return subprocess.CompletedProcess([], 0, "", "")
            if operation == "get":
                remote_path, local_path = parts[1], Path(parts[2])
                if remote_path not in remote_store:
                    return subprocess.CompletedProcess([], 1, "", "NT_STATUS_NO_SUCH_FILE")
                payload = remote_store[remote_path]
                if corrupt_final["name"] == remote_path:
                    payload = b"SYNTHETIC_FINAL_CORRUPTION\n"
                local_path.write_bytes(payload)
                return subprocess.CompletedProcess([], 0, "", "")
            if operation == "rename":
                remote_store[parts[2]] = remote_store.pop(parts[1])
                return subprocess.CompletedProcess([], 0, "", "")
            if operation == "del":
                remote_store.pop(parts[1], None)
                return subprocess.CompletedProcess([], 0, "", "")
            raise AssertionError(f"unexpected synthetic command: {command}")

        Handler._log_smb_stage = staticmethod(record_stage)
        Handler._diagnose_smb_list_result = staticmethod(lambda *_args, **_kwargs: None)
        Handler._smb_command = fake_smb_command

        pdf_payload = b"SYNTHETIC_PDF_PAYLOAD\n"
        pdf_path = root / "VOXEL_SYNTHETIC.pdf"
        pdf_path.write_bytes(pdf_payload)
        credentials = root / "synthetic-credential-file"
        credentials.write_text("synthetic-reference\n", encoding="utf-8")

        final_path, temporary_path = handler._smb_remote_path(pdf_path.name)
        assert final_path == pdf_path.name
        assert temporary_path.startswith(".voxel-") and temporary_path.endswith(".part")
        assert handler._smb_arg(pdf_path.name) == '"VOXEL_SYNTHETIC.pdf"'

        handler.transfer_smb(
            9001,
            pdf_path.name,
            pdf_path,
            sha256_file(pdf_path),
            pdf_path.stat().st_size,
            credentials,
        )
        assert remote_store[final_path] == pdf_payload
        assert_sequence(
            stages,
            [
                ("LIST", "final"),
                ("WRITE", "unknown"),
                ("VERIFY", "temporary"),
                ("RENAME", "unknown"),
                ("VERIFY", "final"),
                ("LIST", "final"),
            ],
        )
        assert not any(path.name.endswith(".part") for path in state_root.iterdir())

        remote_store.clear()
        commands.clear()
        stages.clear()
        corrupt_final["name"] = None
        xml_path = root / "VOXEL_SYNTHETIC.xml"
        xml_payload = synthetic_xml(pdf_path.name)
        xml_path.write_bytes(xml_payload)
        xml_task_file_path_hash = hashlib.sha256(pdf_path.name.encode("utf-8")).hexdigest()
        result = handler.deliver_submission_package_remote(
            9002,
            pdf_path.name,
            pdf_path,
            xml_path.name,
            xml_path,
            xml_task_file_path_hash,
            False,
            credentials,
        )
        assert result == "smb"
        assert remote_store[final_path] == pdf_payload
        assert remote_store[xml_path.name] == xml_payload
        assert_sequence(
            stages,
            [
                ("LIST", "final"),
                ("LIST", "final"),
                ("WRITE", "unknown"),
                ("WRITE", "unknown"),
                ("VERIFY", "temporary"),
                ("VERIFY", "temporary"),
                ("RENAME", "unknown"),
                ("RENAME", "unknown"),
                ("VERIFY", "final"),
                ("VERIFY", "final"),
                ("LIST", "final"),
                ("LIST", "final"),
            ],
        )
        assert not any(path.name.endswith(".part") for path in state_root.iterdir())

        remote_store.clear()
        commands.clear()
        stages.clear()
        corrupt_final["name"] = xml_path.name
        try:
            handler.deliver_submission_package_remote(
                9003,
                pdf_path.name,
                pdf_path,
                xml_path.name,
                xml_path,
                xml_task_file_path_hash,
                False,
                credentials,
            )
        except BridgeTransferError as error:
            assert error.category == "remote_io"
        else:
            raise AssertionError("XML final hash mismatch must fail closed")
        assert stages[-1] == ("VERIFY", "final", "remote_io")
        assert not any(path.name.endswith(".part") for path in state_root.iterdir())
        print("FINAL_XML_HASH_MISMATCH_FAIL_CLOSED=PASS")

        remote_store.clear()
        commands.clear()
        stages.clear()
        corrupt_final["name"] = final_path
        try:
            handler.transfer_smb(
                9004,
                pdf_path.name,
                pdf_path,
                sha256_file(pdf_path),
                pdf_path.stat().st_size,
                credentials,
            )
        except BridgeTransferError as error:
            assert error.category == "remote_io"
        else:
            raise AssertionError("final hash mismatch must fail closed")
        assert stages[-1] == ("VERIFY", "final", "remote_io")
        assert final_path in remote_store
        assert remote_store[final_path] == pdf_payload
        assert not any(path.name.endswith(".part") for path in state_root.iterdir())

        print("PHILIPS_SMB_VERIFY_SYNTHETIC_OK")
        print("PDF_ONLY_FLOW=PASS")
        print("PDF_XML_FLOW=PASS")
        print("FINAL_HASH_MISMATCH_FAIL_CLOSED=PASS")
        print("NETWORK=NO")
        print("CLINICAL_DATA=NO")
        print("COMMANDS_CAPTURED=%d" % len(commands))


if __name__ == "__main__":
    main()
