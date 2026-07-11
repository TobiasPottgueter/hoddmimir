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

    if len(arguments) < 4 or arguments[0] != "compose":
        return 2

    compose_files: list[Path] = []
    argument_index = 1
    while argument_index + 1 < len(arguments) and arguments[argument_index] == "--file":
        compose_files.append(Path(arguments[argument_index + 1]))
        argument_index += 2
    if not compose_files or argument_index >= len(arguments):
        return 2

    operation_arguments = arguments[argument_index:]
    operation = operation_arguments[0]
    if operation == "run" and "doctrine:migrations:up-to-date" in operation_arguments:
        operation = "schema-check"
    counts = state.setdefault("counts", {})
    counts[operation] = counts.get(operation, 0) + 1
    call_number = counts[operation]
    compose_hash = hashlib.sha256()
    for compose_file in compose_files:
        if not compose_file.is_file():
            return 2
        compose_hash.update(compose_file.read_bytes())
    state.setdefault("calls", []).append(
        {
            "operation": operation,
            "call": call_number,
            "compose_hash": compose_hash.hexdigest(),
            "compose_file_count": len(compose_files),
            "arguments": operation_arguments,
        },
    )

    if operation == "down" and any(argument in {"--volumes", "-v"} for argument in operation_arguments[1:]):
        state["forbidden_volume_delete"] = True
        state_path.write_text(json.dumps(state), encoding="utf-8")
        return 97

    failures = state.get("failures", {}).get(operation, [])
    if call_number in failures:
        state_path.write_text(json.dumps(state), encoding="utf-8")
        return 1

    if operation == "up":
        if operation_arguments[-1:] == ["mariadb"]:
            state["running_services"] = sorted(set(state.get("running_services", [])) | {"mariadb"})
        else:
            state["running_services"] = state["expected_services"]
        state["active_compose_hash"] = compose_hash.hexdigest()
    elif operation == "down":
        state["running_services"] = []
        state["active_compose_hash"] = None
    elif operation == "ps":
        for service in state.get("running_services", []):
            print(service)
    elif operation == "schema-check":
        state_path.write_text(json.dumps(state), encoding="utf-8")
        if state.get("schema_check_returncode") is not None:
            return int(state["schema_check_returncode"])
        return 0 if state.get("schema_up_to_date", False) else 1
    elif operation not in {"config", "pull", "run"}:
        state_path.write_text(json.dumps(state), encoding="utf-8")
        return 2

    state_path.write_text(json.dumps(state), encoding="utf-8")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
