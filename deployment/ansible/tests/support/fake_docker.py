#!/usr/bin/env python3
"""Small stateful Docker Compose fake used by local transaction tests."""

from __future__ import annotations

import hashlib
import json
import os
import sys
from pathlib import Path


def main() -> int:
    state_path = Path(os.environ["FAKE_DOCKER_STATE"])
    state = json.loads(state_path.read_text(encoding="utf-8"))
    arguments = sys.argv[1:]

    if len(arguments) < 4 or arguments[0] != "compose" or arguments[1] != "--file":
        return 2

    compose_file = Path(arguments[2])
    operation = arguments[3]
    counts = state.setdefault("counts", {})
    counts[operation] = counts.get(operation, 0) + 1
    call_number = counts[operation]
    compose_hash = hashlib.sha256(compose_file.read_bytes()).hexdigest() if compose_file.is_file() else None
    state.setdefault("calls", []).append(
        {
            "operation": operation,
            "call": call_number,
            "compose_hash": compose_hash,
            "arguments": arguments[3:],
        },
    )

    if operation == "down" and any(argument in {"--volumes", "-v"} for argument in arguments[4:]):
        state["forbidden_volume_delete"] = True
        state_path.write_text(json.dumps(state), encoding="utf-8")
        return 97

    failures = state.get("failures", {}).get(operation, [])
    if call_number in failures:
        state_path.write_text(json.dumps(state), encoding="utf-8")
        return 1

    if operation == "up":
        state["running_services"] = state["expected_services"]
        state["active_compose_hash"] = compose_hash
    elif operation == "down":
        state["running_services"] = []
        state["active_compose_hash"] = None
    elif operation == "ps":
        for service in state.get("running_services", []):
            print(service)
    elif operation not in {"config", "pull"}:
        state_path.write_text(json.dumps(state), encoding="utf-8")
        return 2

    state_path.write_text(json.dumps(state), encoding="utf-8")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
