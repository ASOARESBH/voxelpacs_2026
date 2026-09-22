#!/usr/bin/env python3
"""Gera um par X25519 raw para o envelope efêmero Philips; não recebe nem grava senha SMB."""
from __future__ import annotations

import base64
import os
import sys
from pathlib import Path

from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.x25519 import X25519PrivateKey


def write_private(path: Path, content: bytes) -> None:
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "wb") as handle:
        handle.write(content)
        handle.flush()
        os.fsync(handle.fileno())


def main() -> int:
    if len(sys.argv) != 3:
        return 64
    private_path = Path(sys.argv[1])
    public_path = Path(sys.argv[2])
    private = X25519PrivateKey.generate()
    public = private.public_key()
    private_raw = private.private_bytes(
        serialization.Encoding.Raw,
        serialization.PrivateFormat.Raw,
        serialization.NoEncryption(),
    )
    public_raw = public.public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)
    write_private(private_path, base64.b64encode(private_raw) + b"\n")
    write_private(public_path, base64.b64encode(public_raw) + b"\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
