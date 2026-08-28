#!/usr/bin/env python3
"""Executado dentro do container gateway somente pela ponte autenticada."""
from __future__ import annotations

import sys
from pathlib import Path

from pydicom import dcmread
from pynetdicom import AE
from pynetdicom.sop_class import EncapsulatedPDFStorage, Verification


def fail(code: str) -> None:
    print(code, flush=True)
    raise SystemExit(1)


def main() -> None:
    if len(sys.argv) != 6:
        fail("invalid_arguments")
    artifact, host, port_raw, calling_ae, called_ae = sys.argv[1:]
    try:
        port = int(port_raw)
    except ValueError:
        fail("invalid_port")
    if host != "10.200.10.2" or port != 2104 or calling_ae != "VOXEL_GW_A" or called_ae != "srvpvuerepFIR":
        fail("policy_rejected")
    path = Path(artifact)
    if not path.is_file() or path.stat().st_size < 256 or path.stat().st_size > 20 * 1024 * 1024:
        fail("invalid_artifact")
    try:
        dataset = dcmread(str(path), force=False)
    except Exception:
        fail("invalid_dicom")
    if str(getattr(dataset, "SOPClassUID", "")) != str(EncapsulatedPDFStorage):
        fail("unsupported_sop_class")
    if not getattr(dataset, "EncapsulatedDocument", None):
        fail("missing_encapsulated_pdf")

    ae = AE(ae_title=calling_ae)
    ae.acse_timeout = 15
    ae.dimse_timeout = 30
    ae.network_timeout = 30
    ae.add_requested_context(Verification)
    ae.add_requested_context(EncapsulatedPDFStorage)
    association = ae.associate(host, port, ae_title=called_ae)
    if not association.is_established:
        fail("association_rejected")
    try:
        echo_status = association.send_c_echo()
        if not echo_status or int(echo_status.Status) != 0x0000:
            fail("cecho_failed")
        store_status = association.send_c_store(dataset)
        if not store_status or int(store_status.Status) != 0x0000:
            fail("cstore_failed")
    finally:
        if association.is_established:
            association.release()


if __name__ == "__main__":
    main()
