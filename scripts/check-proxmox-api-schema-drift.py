#!/usr/bin/env python3
"""Check selected Hoddmimir contracts against official Proxmox API viewers."""

from __future__ import annotations

import argparse
import datetime
import email.utils
import hashlib
import json
import re
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any
from urllib.parse import urlparse


DEFAULT_BASELINE = (
    Path(__file__).resolve().parents[1]
    / "docs"
    / "proxmox-official-api-baseline.json"
)
MAXIMUM_SOURCE_BYTES = 8 * 1024 * 1024
OFFICIAL_HOSTS = {"pve.proxmox.com", "pbs.proxmox.com"}
OFFICIAL_SOURCES = {
    ("pve", 7): (
        "https://pve.proxmox.com/pve-docs-7/api-viewer/apidoc.js",
        "https://pve.proxmox.com/pve-docs-7/",
    ),
    ("pve", 8): (
        "https://pve.proxmox.com/pve-docs-8/api-viewer/apidoc.js",
        "https://pve.proxmox.com/pve-docs-8/",
    ),
    ("pve", 9): (
        "https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js",
        "https://pve.proxmox.com/pve-docs/",
    ),
    ("pbs", 3): (
        "https://pbs.proxmox.com/docs-3/api-viewer/apidoc.js",
        "https://pbs.proxmox.com/docs-3/",
    ),
    ("pbs", 4): (
        "https://pbs.proxmox.com/docs/api-viewer/apidoc.js",
        "https://pbs.proxmox.com/docs/",
    ),
}
ASSIGNMENT = re.compile(r"(?:const|var)\s+apiSchema\s*=\s*")
SCHEMA_KEYS = {
    "additionalItems",
    "additionalProperties",
    "allOf",
    "anyOf",
    "conflicts",
    "contains",
    "const",
    "contentEncoding",
    "contentMediaType",
    "default",
    "dependentRequired",
    "dependentSchemas",
    "else",
    "enum",
    "exclusiveMaximum",
    "exclusiveMinimum",
    "format",
    "if",
    "items",
    "maxContains",
    "maxItems",
    "maxLength",
    "maximum",
    "maxProperties",
    "minContains",
    "minItems",
    "minLength",
    "minimum",
    "minProperties",
    "multipleOf",
    "not",
    "optional",
    "oneOf",
    "pattern",
    "prefixItems",
    "properties",
    "propertyNames",
    "required",
    "requires",
    "requires-all",
    "requires-any",
    "then",
    "type",
    "unevaluatedProperties",
    "uniqueItems",
}
OPERATION_KEYS = {
    "allowtoken",
    "method",
    "parameters",
    "protected",
    "proxyto",
    "returns",
}
REQUEST_RESTRICTIVE_KEYS = {
    "additionalItems",
    "allOf",
    "anyOf",
    "conflicts",
    "contains",
    "const",
    "contentEncoding",
    "contentMediaType",
    "dependentRequired",
    "dependentSchemas",
    "else",
    "enum",
    "exclusiveMaximum",
    "exclusiveMinimum",
    "format",
    "if",
    "items",
    "maxContains",
    "maxItems",
    "maxLength",
    "maximum",
    "maxProperties",
    "minContains",
    "minItems",
    "minLength",
    "minimum",
    "minProperties",
    "multipleOf",
    "not",
    "oneOf",
    "pattern",
    "prefixItems",
    "propertyNames",
    "required",
    "requires",
    "requires-all",
    "requires-any",
    "then",
    "type",
    "unevaluatedProperties",
    "uniqueItems",
}


class DriftError(RuntimeError):
    """A bounded, user-facing schema drift failure."""


class RejectRedirects(urllib.request.HTTPRedirectHandler):
    """Reject redirects before urllib can issue a request to the next hop."""

    def redirect_request(
        self,
        request: urllib.request.Request,
        file_pointer: Any,
        code: int,
        message: str,
        headers: Any,
        new_url: str,
    ) -> None:
        validate_source_url(new_url)
        raise DriftError("official source redirected; redirects are disabled")


def parse_api_schema(payload: bytes) -> list[dict[str, Any]]:
    try:
        text = payload.decode("utf-8")
    except UnicodeDecodeError as exception:
        raise DriftError("official API schema is not valid UTF-8") from exception
    assignment = ASSIGNMENT.search(text)
    if assignment is None:
        raise DriftError("official API schema assignment was not found")
    try:
        value, _ = json.JSONDecoder().raw_decode(text, assignment.end())
    except json.JSONDecodeError as exception:
        raise DriftError("official API schema payload is not valid JSON data") from exception
    if not isinstance(value, list) or not all(isinstance(node, dict) for node in value):
        raise DriftError("official API schema root must be a list of objects")
    return value


def index_operations(nodes: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    operations: dict[str, dict[str, Any]] = {}
    pending = list(reversed(nodes))
    while pending:
        node = pending.pop()
        path = node.get("path")
        info = node.get("info", {})
        if isinstance(path, str) and isinstance(info, dict):
            for method, operation in info.items():
                if not isinstance(method, str) or not isinstance(operation, dict):
                    raise DriftError("invalid operation metadata in official schema")
                key = f"{method.upper()} {path}"
                if key in operations:
                    raise DriftError("duplicate operation in official schema")
                operations[key] = operation
        children = node.get("children", [])
        if not isinstance(children, list) or not all(
            isinstance(child, dict) for child in children
        ):
            raise DriftError("official API schema children must be a list of objects")
        pending.extend(reversed(children))
    return operations


def semantic_schema(value: Any) -> Any:
    if isinstance(value, dict):
        projected: dict[str, Any] = {}
        for key in sorted(value):
            if key not in SCHEMA_KEYS:
                continue
            child = value[key]
            if key == "properties":
                if not isinstance(child, dict):
                    raise DriftError("schema properties must be an object")
                projected[key] = {
                    name: semantic_schema(schema)
                    for name, schema in sorted(child.items())
                }
            else:
                projected[key] = semantic_schema(child)
        return projected
    if isinstance(value, list):
        return [semantic_schema(item) for item in value]
    return value


def semantic_operation(operation: dict[str, Any]) -> dict[str, Any]:
    projected: dict[str, Any] = {}
    for key in sorted(operation):
        if key not in OPERATION_KEYS:
            continue
        value = operation[key]
        projected[key] = (
            semantic_schema(value) if key in {"parameters", "returns"} else value
        )
    return projected


def compatibility_issues(expected: Any, current: Any, location: str) -> list[str]:
    issues: list[str] = []
    if isinstance(expected, dict):
        if not isinstance(current, dict):
            return [f"{location}: expected object, got {type(current).__name__}"]
        for key, value in expected.items():
            child_location = f"{location}.{key}"
            if key not in current:
                issues.append(f"{child_location}: removed")
                continue
            issues.extend(compatibility_issues(value, current[key], child_location))
        return issues
    if isinstance(expected, list):
        if not isinstance(current, list):
            return [f"{location}: expected list, got {type(current).__name__}"]
        for item in expected:
            if item not in current:
                issues.append(f"{location}: baseline list item removed: {item!r}")
        return issues
    if expected != current:
        issues.append(f"{location}: changed from {expected!r} to {current!r}")
    return issues


def semantic_additions(expected: Any, current: Any, location: str) -> list[str]:
    additions: list[str] = []
    if isinstance(expected, dict) and isinstance(current, dict):
        for key in current.keys() - expected.keys():
            additions.append(f"{location}.{key}")
        for key in expected.keys() & current.keys():
            additions.extend(
                semantic_additions(expected[key], current[key], f"{location}.{key}")
            )
    elif isinstance(expected, list) and isinstance(current, list):
        for item in current:
            if item not in expected:
                additions.append(f"{location}[] = {item!r}")
    return additions


def constraint_locations(value: Any, location: str) -> list[str]:
    locations: list[str] = []
    if isinstance(value, dict):
        for key, child in value.items():
            child_location = f"{location}.{key}"
            if key in {
                "conflicts",
                "required",
                "requires",
                "requires-all",
                "requires-any",
            }:
                locations.append(child_location)
            locations.extend(constraint_locations(child, child_location))
    elif isinstance(value, list):
        for index, child in enumerate(value):
            locations.extend(constraint_locations(child, f"{location}[{index}]"))
    return locations


def breaking_addition_issues(
    expected: Any,
    current: Any,
    location: str,
    open_response_enums: set[str],
) -> list[str]:
    issues: list[str] = []
    if isinstance(expected, dict) and isinstance(current, dict):
        added_keys = current.keys() - expected.keys()
        request_schema = ".parameters" in location
        if request_schema and location.endswith(".properties"):
            for parameter in added_keys:
                schema = current[parameter]
                optional = isinstance(schema, dict) and schema.get("optional") in {
                    True,
                    1,
                }
                if not optional:
                    issues.append(
                        f"{location}.{parameter}: new required request parameter"
                    )
                for constraint in constraint_locations(
                    schema, f"{location}.{parameter}"
                ):
                    issues.append(f"{constraint}: new request constraint")
        for key in added_keys:
            child_location = f"{location}.{key}"
            if request_schema and key in REQUEST_RESTRICTIVE_KEYS:
                issues.append(f"{child_location}: new request constraint")
            if (
                request_schema
                and key == "additionalProperties"
                and (current[key] is False or current[key] == 0)
            ):
                issues.append(f"{child_location}: new request constraint")
            if request_schema and key == "optional" and (
                current[key] is False or current[key] == 0
            ):
                issues.append(f"{child_location}: new request constraint")
            if (
                key == "enum"
                and ".returns" in location
                and child_location not in open_response_enums
            ):
                issues.append(f"{child_location}: new closed response enum")
            if request_schema and key == "properties" and isinstance(current[key], dict):
                issues.extend(
                    breaking_addition_issues(
                        {},
                        current[key],
                        child_location,
                        open_response_enums,
                    )
                )
        for key in expected.keys() & current.keys():
            issues.extend(
                breaking_addition_issues(
                    expected[key],
                    current[key],
                    f"{location}.{key}",
                    open_response_enums,
                )
            )
    elif isinstance(expected, list) and isinstance(current, list):
        if location.endswith(".required") and ".parameters" in location:
            for item in current:
                if item not in expected:
                    issues.append(
                        f"{location}: new required request parameter {item!r}"
                    )
        if (
            location.endswith(".enum")
            and ".returns" in location
            and location not in open_response_enums
        ):
            for item in current:
                if item not in expected:
                    issues.append(
                        f"{location}: closed response enum added value {item!r}"
                    )
    return issues


def response_enum_locations(value: Any, location: str) -> set[str]:
    locations: set[str] = set()
    if isinstance(value, dict):
        for key, child in value.items():
            child_location = f"{location}.{key}"
            if key == "enum" and ".returns" in location and isinstance(child, list):
                locations.add(child_location)
            locations.update(response_enum_locations(child, child_location))
    elif isinstance(value, list):
        for index, child in enumerate(value):
            locations.update(response_enum_locations(child, f"{location}[{index}]"))
    return locations


def validate_source_url(url: str) -> None:
    parsed = urlparse(url)
    if parsed.scheme != "https" or parsed.hostname not in OFFICIAL_HOSTS:
        raise DriftError("source URL must use an allowlisted official HTTPS host")
    if parsed.username is not None or parsed.password is not None or parsed.query:
        raise DriftError("source URL must not contain credentials or query data")


def fetch_source(url: str, timeout: float) -> bytes:
    validate_source_url(url)
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "Hoddmimir/2.0 official-api-schema-drift"},
    )
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler({}),
        RejectRedirects(),
    )
    try:
        with opener.open(request, timeout=timeout) as response:
            payload = response.read(MAXIMUM_SOURCE_BYTES + 1)
    except DriftError:
        raise
    except (urllib.error.URLError, TimeoutError) as exception:
        raise DriftError("official source download failed") from exception
    if len(payload) > MAXIMUM_SOURCE_BYTES:
        raise DriftError(f"official source exceeds {MAXIMUM_SOURCE_BYTES} bytes")
    return payload


def read_source(source: dict[str, Any], source_directory: Path | None, timeout: float) -> bytes:
    source_id = source.get("id")
    if not isinstance(source_id, str) or not re.fullmatch(r"[a-z0-9-]+", source_id):
        raise DriftError("baseline source id is invalid")
    if source_directory is not None:
        path = source_directory / f"{source_id}.js"
        try:
            payload = path.read_bytes()
        except OSError as exception:
            raise DriftError(f"cannot read offline source for {source_id}") from exception
        if len(payload) > MAXIMUM_SOURCE_BYTES:
            raise DriftError(
                f"offline schema for {source_id} exceeds {MAXIMUM_SOURCE_BYTES} bytes"
            )
        return payload
    url = source.get("url")
    if not isinstance(url, str):
        raise DriftError(f"baseline source {source_id} has no URL")
    return fetch_source(url, timeout)


def assert_documentation_major(
    source: dict[str, Any], source_directory: Path | None, timeout: float
) -> None:
    documentation_url = source.get("documentationUrl")
    if documentation_url is None:
        return
    if not isinstance(documentation_url, str):
        raise DriftError("documentation URL must be a string")
    validate_source_url(documentation_url)
    source_id = source["id"]
    if source_directory is None:
        payload = fetch_source(documentation_url, timeout)
    else:
        path = source_directory / f"{source_id}.version.html"
        try:
            payload = path.read_bytes()
        except OSError as exception:
            raise DriftError(
                f"cannot read offline version marker for {source_id}"
            ) from exception
    try:
        text = payload.decode("utf-8")
    except UnicodeDecodeError as exception:
        raise DriftError(
            f"documentation version marker is not UTF-8 for {source_id}"
        ) from exception
    product = source.get("product")
    major = source.get("major")
    if product == "pve" and isinstance(major, int):
        pattern = rf'id="revnumber">version\s+{major}\.'
    elif product == "pbs" and isinstance(major, int):
        pattern = rf"Proxmox Backup\s+{major}\."
    else:
        raise DriftError(f"product or major is invalid for {source_id}")
    if re.search(pattern, text, re.IGNORECASE) is None:
        raise DriftError(
            f"official documentation no longer identifies {source_id} as major {major}"
        )


def validate_source_identity(source: dict[str, Any]) -> None:
    source_id = source.get("id")
    product = source.get("product")
    major = source.get("major")
    if not isinstance(product, str) or not isinstance(major, int):
        raise DriftError("source provenance, product, or major is invalid")
    provenance = OFFICIAL_SOURCES.get((product, major))
    if (
        not isinstance(source_id, str)
        or source_id != f"{product}-{major}"
        or provenance is None
        or source.get("url") != provenance[0]
        or source.get("documentationUrl") != provenance[1]
    ):
        raise DriftError("source provenance, product, or major is invalid")
    validate_source_url(provenance[0])
    validate_source_url(provenance[1])


def validate_metadata_fields(source: dict[str, Any]) -> None:
    retrieved_at = source.get("retrievedAt")
    if not isinstance(retrieved_at, str):
        raise DriftError("source retrieval date is invalid")
    try:
        datetime.date.fromisoformat(retrieved_at)
    except ValueError as exception:
        raise DriftError("source retrieval date is invalid") from exception
    for key in ["etag", "lastModified"]:
        value = source.get(key)
        if (
            not isinstance(value, str)
            or not value
            or len(value) > 512
            or "\r" in value
            or "\n" in value
        ):
            raise DriftError(f"source {key} metadata is invalid")
    try:
        parsed = email.utils.parsedate_to_datetime(source["lastModified"])
    except (TypeError, ValueError) as exception:
        raise DriftError("source lastModified metadata is invalid") from exception
    if parsed.tzinfo is None:
        raise DriftError("source lastModified metadata must include a timezone")


def read_refresh_metadata(
    source: dict[str, Any], source_directory: Path, payload: bytes
) -> dict[str, str]:
    path = source_directory / f"{source['id']}.metadata.json"
    try:
        metadata = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exception:
        raise DriftError("reviewed refresh metadata is missing or invalid") from exception
    expected_keys = {
        "documentationUrl",
        "etag",
        "lastModified",
        "retrievedAt",
        "schemaUrl",
        "sha256",
    }
    if not isinstance(metadata, dict) or set(metadata) != expected_keys:
        raise DriftError("reviewed refresh metadata fields are invalid")
    if (
        metadata["schemaUrl"] != source["url"]
        or metadata["documentationUrl"] != source["documentationUrl"]
        or metadata["sha256"] != hashlib.sha256(payload).hexdigest()
    ):
        raise DriftError("reviewed refresh metadata does not match its source")
    candidate = {
        "retrievedAt": metadata["retrievedAt"],
        "etag": metadata["etag"],
        "lastModified": metadata["lastModified"],
    }
    validate_metadata_fields(candidate)
    return {key: str(value) for key, value in metadata.items()}


def validate_baseline(baseline: dict[str, Any]) -> list[dict[str, Any]]:
    if baseline.get("format") != 1:
        raise DriftError("unsupported baseline format")
    sources = baseline.get("sources")
    if not isinstance(sources, list) or not sources:
        raise DriftError("baseline must contain sources")
    seen: set[str] = set()
    for source in sources:
        if not isinstance(source, dict):
            raise DriftError("baseline sources must be objects")
        source_id = source.get("id")
        if not isinstance(source_id, str) or source_id in seen:
            raise DriftError("baseline source ids must be unique strings")
        seen.add(source_id)
        validate_source_identity(source)
        validate_metadata_fields(source)
        capabilities = source.get("capabilities")
        contracts = source.get("contracts")
        if not isinstance(capabilities, list) or not all(
            isinstance(capability, str) for capability in capabilities
        ):
            raise DriftError(f"baseline capabilities are invalid for {source_id}")
        if len(set(capabilities)) != len(capabilities):
            raise DriftError(f"baseline capabilities contain duplicates for {source_id}")
        if not all(
            re.fullmatch(r"(?:GET|POST|PUT|DELETE|PATCH) /[^?\s]*", capability)
            for capability in capabilities
        ):
            raise DriftError(f"baseline capability syntax is invalid for {source_id}")
        if not isinstance(contracts, dict) or set(contracts) != set(capabilities):
            raise DriftError(
                f"contracts must exactly match reviewed capabilities for {source_id}"
            )
        digest = source.get("sha256")
        if not isinstance(digest, str) or not re.fullmatch(r"[0-9a-f]{64}", digest):
            raise DriftError(f"baseline SHA-256 is invalid for {source_id}")
        open_response_enums = source.get("openResponseEnums")
        if not isinstance(open_response_enums, list) or not all(
            isinstance(location, str)
            and ".returns" in location
            and location.endswith(".enum")
            and any(location.startswith(capability + ".") for capability in capabilities)
            for location in open_response_enums
        ):
            raise DriftError(f"open response enum policy is invalid for {source_id}")
        if len(set(open_response_enums)) != len(open_response_enums):
            raise DriftError(f"open response enum policy has duplicates for {source_id}")
        known_response_enums: set[str] = set()
        for capability, contract in contracts.items():
            known_response_enums.update(response_enum_locations(contract, capability))
        if not set(open_response_enums).issubset(known_response_enums):
            raise DriftError(
                f"open response enum policy references an unknown enum for {source_id}"
            )
    return sources


def check_baseline(
    baseline: dict[str, Any], source_directory: Path | None, timeout: float
) -> int:
    sources = validate_baseline(baseline)
    failures: list[str] = []
    notices: list[str] = []
    for source in sources:
        source_id = source["id"]
        assert_documentation_major(source, source_directory, timeout)
        payload = read_source(source, source_directory, timeout)
        digest = hashlib.sha256(payload).hexdigest()
        operations = index_operations(parse_api_schema(payload))
        if digest != source["sha256"]:
            notices.append(
                f"{source_id}: raw SHA-256 changed {source['sha256']} -> {digest}"
            )
        for capability in source["capabilities"]:
            operation = operations.get(capability)
            if operation is None:
                failures.append(f"{source_id} {capability}: operation removed")
                continue
            expected = source["contracts"][capability]
            current = semantic_operation(operation)
            contract_failures = compatibility_issues(expected, current, capability)
            contract_failures.extend(
                breaking_addition_issues(
                    expected,
                    current,
                    capability,
                    set(source["openResponseEnums"]),
                )
            )
            failures.extend(
                f"{source_id} {issue}" for issue in contract_failures
            )
            if digest != source["sha256"] and not contract_failures:
                additions = semantic_additions(expected, current, capability)
                if additions:
                    preview = ", ".join(additions[:20])
                    suffix = "" if len(additions) <= 20 else f" (+{len(additions) - 20} more)"
                    notices.append(
                        f"{source_id}: compatible additive schema data: {preview}{suffix}"
                    )
    for notice in notices:
        print(f"NOTICE: {notice}")
    if failures:
        for failure in failures[:100]:
            print(f"ERROR: {failure}", file=sys.stderr)
        if len(failures) > 100:
            print(
                f"ERROR: {len(failures) - 100} additional incompatibilities omitted",
                file=sys.stderr,
            )
        return 1
    print(
        "Official Proxmox API schemas remain compatible with all explicitly reviewed capabilities."
    )
    if notices:
        print(
            "Upstream bytes changed compatibly; no capability was enabled or baseline updated."
        )
    return 0


def refresh_baseline(
    baseline: dict[str, Any], source_directory: Path, output: Path
) -> None:
    sources = validate_baseline(baseline)
    for source in sources:
        validate_source_identity(source)
        assert_documentation_major(source, source_directory, timeout=0)
        payload = read_source(source, source_directory, timeout=0)
        metadata = read_refresh_metadata(source, source_directory, payload)
        operations = index_operations(parse_api_schema(payload))
        capabilities = source.get("capabilities")
        if not isinstance(capabilities, list) or not all(
            isinstance(capability, str) for capability in capabilities
        ):
            raise DriftError("refresh requires an explicit capability allowlist")
        contracts: dict[str, Any] = {}
        for capability in capabilities:
            operation = operations.get(capability)
            if operation is None:
                raise DriftError(f"reviewed capability is absent: {source.get('id')} {capability}")
            contracts[capability] = semantic_operation(operation)
        source["retrievedAt"] = metadata["retrievedAt"]
        source["lastModified"] = metadata["lastModified"]
        source["etag"] = metadata["etag"]
        source["sha256"] = metadata["sha256"]
        source["contracts"] = contracts
    output.write_text(
        json.dumps(baseline, ensure_ascii=False, indent=2, sort_keys=False) + "\n",
        encoding="utf-8",
    )


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--baseline", type=Path, default=DEFAULT_BASELINE)
    parser.add_argument("--source-directory", type=Path)
    parser.add_argument("--timeout", type=float, default=30.0)
    parser.add_argument(
        "--refresh",
        action="store_true",
        help="refresh only the explicitly listed capabilities from offline sources",
    )
    parser.add_argument("--output", type=Path)
    return parser.parse_args()


def main() -> int:
    arguments = parse_arguments()
    try:
        baseline = json.loads(arguments.baseline.read_text(encoding="utf-8"))
        if not isinstance(baseline, dict):
            raise DriftError("baseline root must be an object")
        if arguments.refresh:
            if arguments.source_directory is None or arguments.output is None:
                raise DriftError("--refresh requires --source-directory and --output")
            refresh_baseline(baseline, arguments.source_directory, arguments.output)
            return 0
        return check_baseline(baseline, arguments.source_directory, arguments.timeout)
    except OSError:
        print("ERROR: local schema drift file operation failed", file=sys.stderr)
        return 1
    except json.JSONDecodeError:
        print("ERROR: baseline JSON is invalid", file=sys.stderr)
        return 1
    except DriftError as exception:
        print(f"ERROR: {exception}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
