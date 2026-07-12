from __future__ import annotations

import contextlib
import hashlib
import importlib.util
import io
import json
import tempfile
import unittest
from pathlib import Path
from unittest import mock


SCRIPT = Path(__file__).resolve().parents[1] / "check-proxmox-api-schema-drift.py"
REPOSITORY = SCRIPT.parents[1]
SPEC = importlib.util.spec_from_file_location("proxmox_api_schema_drift", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("cannot load Proxmox schema drift checker")
drift = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(drift)


def payload(
    *,
    return_type: str = "string",
    additive: bool = False,
    include_version: bool = True,
    include_unreviewed: bool = False,
    declaration: str = "const",
    request_parameter: str | None = None,
    request_optional: bool = False,
    request_constraint: str | None = None,
    request_constraints: dict[str, object] | None = None,
    request_schema: dict[str, object] | None = None,
    return_enum: list[str] | None = None,
) -> bytes:
    nodes: list[dict[str, object]] = []
    if include_version:
        version_schema: dict[str, object] = {"type": return_type}
        if return_enum is not None:
            version_schema["enum"] = return_enum
        properties: dict[str, object] = {"version": version_schema}
        if additive:
            properties["release"] = {"type": "string", "optional": 1}
        parameters: dict[str, object] = {}
        if request_parameter is not None:
            parameter_schema: dict[str, object] = (
                json.loads(json.dumps(request_schema))
                if request_schema is not None
                else {"type": "string"}
            )
            if request_optional:
                parameter_schema["optional"] = 1
            if request_constraint is not None:
                parameter_schema[request_constraint] = "other"
            if request_constraints is not None:
                parameter_schema.update(request_constraints)
            parameters[request_parameter] = parameter_schema
        nodes.append(
            {
                "path": "/version",
                "info": {
                    "GET": {
                        "description": "ignored documentation",
                        "method": "GET",
                        "parameters": {
                            "additionalProperties": False,
                            "properties": parameters,
                            "type": "object",
                        },
                        "returns": {
                            "properties": properties,
                            "type": "object",
                        },
                    }
                },
            }
        )
    if include_unreviewed:
        nodes.append(
            {
                "path": "/new-capability",
                "info": {"POST": {"method": "POST", "returns": {"type": "null"}}},
            }
        )
    encoded = json.dumps(nodes, separators=(",", ":"))
    return f"{declaration} apiSchema = {encoded};\nwindow.viewer = true;\n".encode()


def baseline_for(source: bytes) -> dict[str, object]:
    operation = drift.index_operations(drift.parse_api_schema(source))["GET /version"]
    return {
        "format": 1,
        "sources": [
            {
                "id": "pve-9",
                "product": "pve",
                "major": 9,
                "url": "https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js",
                "documentationUrl": "https://pve.proxmox.com/pve-docs/",
                "retrievedAt": "2026-07-12",
                "lastModified": "Fri, 03 Jul 2026 09:08:20 GMT",
                "etag": "fixture-etag",
                "sha256": hashlib.sha256(source).hexdigest(),
                "capabilities": ["GET /version"],
                "openResponseEnums": [],
                "contracts": {"GET /version": drift.semantic_operation(operation)},
            }
        ],
    }


class ProxmoxApiSchemaDriftTest(unittest.TestCase):
    def check(self, baseline: dict[str, object], current: bytes) -> tuple[int, str, str]:
        with tempfile.TemporaryDirectory() as directory:
            source_directory = Path(directory)
            (source_directory / "pve-9.js").write_bytes(current)
            (source_directory / "pve-9.version.html").write_text(
                '<span id="revnumber">version 9.2.3,</span>'
            )
            stdout = io.StringIO()
            stderr = io.StringIO()
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                result = drift.check_baseline(baseline, source_directory, timeout=1)
            return result, stdout.getvalue(), stderr.getvalue()

    def write_refresh_metadata(self, root: Path, source: bytes) -> None:
        (root / "pve-9.version.html").write_text(
            '<span id="revnumber">version 9.2.3,</span>'
        )
        (root / "pve-9.metadata.json").write_text(
            json.dumps(
                {
                    "schemaUrl": "https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js",
                    "documentationUrl": "https://pve.proxmox.com/pve-docs/",
                    "retrievedAt": "2026-07-13",
                    "lastModified": "Mon, 13 Jul 2026 08:00:00 GMT",
                    "etag": "reviewed-etag",
                    "sha256": hashlib.sha256(source).hexdigest(),
                }
            )
        )

    def test_parses_const_and_var_assignments_with_trailing_viewer_code(self) -> None:
        for declaration in ["const", "var"]:
            schema = drift.parse_api_schema(payload(declaration=declaration))
            operations = drift.index_operations(schema)
            self.assertEqual(["GET /version"], list(operations))

    def test_exact_pinned_schema_passes(self) -> None:
        source = payload()
        result, stdout, stderr = self.check(baseline_for(source), source)
        self.assertEqual(0, result)
        self.assertIn("remain compatible", stdout)
        self.assertEqual("", stderr)

    def test_additive_field_and_raw_hash_change_are_reported_but_allowed(self) -> None:
        source = payload()
        current = payload(additive=True, include_unreviewed=True)
        baseline = baseline_for(source)

        result, stdout, stderr = self.check(baseline, current)

        self.assertEqual(0, result)
        self.assertIn("raw SHA-256 changed", stdout)
        self.assertIn("compatible additive schema data", stdout)
        self.assertIn("no capability was enabled", stdout)
        self.assertEqual(["GET /version"], baseline["sources"][0]["capabilities"])
        self.assertEqual("", stderr)

    def test_removed_operation_fails_closed(self) -> None:
        source = payload()
        result, _, stderr = self.check(
            baseline_for(source), payload(include_version=False)
        )
        self.assertEqual(1, result)
        self.assertIn("operation removed", stderr)

    def test_changed_contract_type_fails_closed(self) -> None:
        source = payload()
        result, _, stderr = self.check(
            baseline_for(source), payload(return_type="integer")
        )
        self.assertEqual(1, result)
        self.assertIn("changed from 'string' to 'integer'", stderr)

    def test_new_required_request_parameter_fails_but_optional_one_is_additive(self) -> None:
        source = payload()
        required_result, _, required_error = self.check(
            baseline_for(source),
            payload(request_parameter="mandatory"),
        )
        optional_result, optional_output, optional_error = self.check(
            baseline_for(source),
            payload(request_parameter="optional", request_optional=True),
        )
        self.assertEqual(1, required_result)
        self.assertIn("new required request parameter", required_error)
        self.assertEqual(0, optional_result)
        self.assertIn("compatible additive schema data", optional_output)
        self.assertEqual("", optional_error)

    def test_new_requires_or_conflicts_request_constraint_fails_closed(self) -> None:
        baseline = baseline_for(
            payload(request_parameter="filter", request_optional=True)
        )
        for constraint in ["requires", "conflicts"]:
            with self.subTest(constraint=constraint):
                result, _, stderr = self.check(
                    baseline,
                    payload(
                        request_parameter="filter",
                        request_optional=True,
                        request_constraint=constraint,
                    ),
                )
                self.assertEqual(1, result)
                self.assertIn("new request constraint", stderr)

    def test_new_restrictive_constraints_on_existing_request_parameter_fail_closed(
        self,
    ) -> None:
        string_source = payload(request_parameter="filter", request_optional=True)
        string_baseline = baseline_for(string_source)
        for key, value in [
            ("minLength", 1),
            ("maxLength", 64),
            ("pattern", "^[a-z]+$"),
            ("const", "fixed"),
            ("format", "pve-configid"),
            ("enum", ["known"]),
        ]:
            with self.subTest(constraint=key):
                result, _, stderr = self.check(
                    string_baseline,
                    payload(
                        request_parameter="filter",
                        request_optional=True,
                        request_constraints={key: value},
                    ),
                )
                self.assertEqual(1, result)
                self.assertIn(f".{key}: new request constraint", stderr)

        numeric_schema = {"type": "integer", "optional": 1}
        numeric_baseline = baseline_for(
            payload(request_parameter="limit", request_schema=numeric_schema)
        )
        for key, value in [("minimum", 1), ("maximum", 100)]:
            with self.subTest(constraint=key):
                result, _, stderr = self.check(
                    numeric_baseline,
                    payload(
                        request_parameter="limit",
                        request_schema=numeric_schema,
                        request_constraints={key: value},
                    ),
                )
                self.assertEqual(1, result)
                self.assertIn(f".{key}: new request constraint", stderr)

    def test_nested_request_objects_detect_implicit_and_explicit_required_fields(
        self,
    ) -> None:
        base_schema = {
            "type": "object",
            "optional": 1,
            "properties": {
                "selection": {
                    "type": "object",
                    "optional": 1,
                    "properties": {},
                }
            },
        }
        baseline = baseline_for(
            payload(request_parameter="options", request_schema=base_schema)
        )

        implicit = json.loads(json.dumps(base_schema))
        implicit["properties"]["selection"]["properties"]["required-child"] = {
            "type": "string"
        }
        implicit_result, _, implicit_error = self.check(
            baseline,
            payload(request_parameter="options", request_schema=implicit),
        )
        self.assertEqual(1, implicit_result)
        self.assertIn("new required request parameter", implicit_error)

        explicit = json.loads(json.dumps(base_schema))
        explicit["properties"]["selection"]["properties"]["child"] = {
            "type": "string",
            "optional": 1,
        }
        explicit["properties"]["selection"]["required"] = ["child"]
        explicit_result, _, explicit_error = self.check(
            baseline,
            payload(request_parameter="options", request_schema=explicit),
        )
        self.assertEqual(1, explicit_result)
        self.assertIn("new request constraint", explicit_error)

    def test_response_enum_addition_requires_an_explicit_open_consumer(self) -> None:
        source = payload(return_enum=["vm"])
        current = payload(return_enum=["vm", "ct"])
        closed = baseline_for(source)
        closed_result, _, closed_error = self.check(closed, current)
        self.assertEqual(1, closed_result)
        self.assertIn("closed response enum added value 'ct'", closed_error)

        opened = baseline_for(source)
        opened["sources"][0]["openResponseEnums"] = [
            "GET /version.returns.properties.version.enum"
        ]
        open_result, open_output, open_error = self.check(opened, current)
        self.assertEqual(0, open_result)
        self.assertIn("compatible additive schema data", open_output)
        self.assertEqual("", open_error)

    def test_refresh_never_discovers_unreviewed_capabilities(self) -> None:
        source = payload(include_unreviewed=True)
        baseline = baseline_for(payload())
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "pve-9.js").write_bytes(source)
            self.write_refresh_metadata(root, source)
            output = root / "baseline.json"
            drift.refresh_baseline(baseline, root, output)
            refreshed = json.loads(output.read_text())

        self.assertEqual(["GET /version"], refreshed["sources"][0]["capabilities"])
        self.assertEqual(["GET /version"], list(refreshed["sources"][0]["contracts"]))
        self.assertEqual("2026-07-13", refreshed["sources"][0]["retrievedAt"])
        self.assertEqual("reviewed-etag", refreshed["sources"][0]["etag"])
        self.assertEqual(
            "Mon, 13 Jul 2026 08:00:00 GMT",
            refreshed["sources"][0]["lastModified"],
        )

    def test_rejects_non_official_or_credential_bearing_sources(self) -> None:
        for url in [
            "https://example.com/apidoc.js",
            "http://pve.proxmox.com/pve-docs/api-viewer/apidoc.js",
            "https://user:secret@pve.proxmox.com/apidoc.js",
            "https://pbs.proxmox.com/docs/api-viewer/apidoc.js?token=secret",
        ]:
            with self.subTest(url=url):
                with self.assertRaises(drift.DriftError) as caught:
                    drift.validate_source_url(url)
                self.assertNotIn("secret", str(caught.exception))
                self.assertNotIn("token", str(caught.exception))

    def test_redirects_are_rejected_before_a_follow_up_request(self) -> None:
        handler = drift.RejectRedirects()
        request = drift.urllib.request.Request(
            "https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js"
        )
        for target in [
            "https://pbs.proxmox.com/docs/api-viewer/apidoc.js",
            "https://example.com/schema.js?token=redirect-secret",
        ]:
            with self.subTest(target=target):
                with self.assertRaises(drift.DriftError) as caught:
                    handler.redirect_request(
                        request, None, 302, "redirect", {}, target
                    )
                self.assertNotIn("secret", str(caught.exception))
                self.assertNotIn("token", str(caught.exception))

    def test_download_failures_never_echo_url_secrets(self) -> None:
        opener = mock.Mock()
        opener.open.side_effect = drift.urllib.error.URLError(
            "https://user:secret@pve.proxmox.com/schema?token=hidden"
        )
        with mock.patch.object(
            drift.urllib.request, "build_opener", return_value=opener
        ) as build_opener:
            with self.assertRaises(drift.DriftError) as caught:
                drift.fetch_source(
                    "https://pve.proxmox.com/pve-docs/api-viewer/apidoc.js", 1
                )
        proxy_handler, redirect_handler = build_opener.call_args.args
        self.assertEqual({}, proxy_handler.proxies)
        self.assertIsInstance(redirect_handler, drift.RejectRedirects)
        opener.open.assert_called_once()
        message = str(caught.exception)
        self.assertNotIn("secret", message)
        self.assertNotIn("token", message)
        self.assertNotIn("?", message)

    def test_refresh_rejects_wrong_provenance_or_major_marker(self) -> None:
        source = payload()
        baseline = baseline_for(source)
        baseline["sources"][0]["product"] = "pbs"
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "pve-9.js").write_bytes(source)
            self.write_refresh_metadata(root, source)
            with self.assertRaises(drift.DriftError):
                drift.refresh_baseline(baseline, root, root / "output.json")

        baseline = baseline_for(source)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "pve-9.js").write_bytes(source)
            self.write_refresh_metadata(root, source)
            (root / "pve-9.version.html").write_text(
                '<span id="revnumber">version 10.0,</span>'
            )
            with self.assertRaises(drift.DriftError):
                drift.refresh_baseline(baseline, root, root / "output.json")

    def test_refresh_rejects_metadata_that_does_not_match_downloaded_bytes(self) -> None:
        source = payload()
        baseline = baseline_for(source)
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "pve-9.js").write_bytes(source)
            self.write_refresh_metadata(root, source)
            metadata_path = root / "pve-9.metadata.json"
            metadata = json.loads(metadata_path.read_text())
            metadata["sha256"] = "0" * 64
            metadata_path.write_text(json.dumps(metadata))
            with self.assertRaises(drift.DriftError):
                drift.refresh_baseline(baseline, root, root / "output.json")

    def test_contract_map_must_exactly_match_reviewed_capabilities(self) -> None:
        source = payload()
        baseline = baseline_for(source)
        baseline["sources"][0]["contracts"]["POST /new-capability"] = {
            "method": "POST"
        }
        with self.assertRaises(drift.DriftError):
            drift.validate_baseline(baseline)

        baseline = baseline_for(source)
        baseline["sources"][0]["openResponseEnums"] = [
            "GET /version.returns.properties.unknown.enum"
        ]
        with self.assertRaises(drift.DriftError):
            drift.validate_baseline(baseline)

    def test_repository_baseline_covers_only_the_five_supported_major_lines(self) -> None:
        baseline = json.loads(
            (REPOSITORY / "docs/proxmox-official-api-baseline.json").read_text()
        )
        sources = drift.validate_baseline(baseline)
        self.assertEqual(
            ["pve-7", "pve-8", "pve-9", "pbs-3", "pbs-4"],
            [source["id"] for source in sources],
        )
        self.assertTrue(
            all(source["capabilities"] for source in sources),
            "every supported major must have an explicit capability allowlist",
        )
        self.assertTrue(all(source.get("documentationUrl") for source in sources))
        for source in sources:
            if source["product"] != "pbs":
                continue
            capability = "GET /admin/datastore/{store}/snapshots"
            expected = source["contracts"][capability]
            current = json.loads(json.dumps(expected))
            response_enum = current["returns"]["items"]["properties"][
                "backup-type"
            ]["enum"]
            response_enum.append("future-type")
            issues = drift.breaking_addition_issues(
                expected,
                current,
                capability,
                set(source["openResponseEnums"]),
            )
            self.assertTrue(
                any("closed response enum" in issue for issue in issues),
                f"{source['id']} backup-type must remain fail closed",
            )

    def test_mutable_current_alias_must_still_identify_the_reviewed_major(self) -> None:
        source = {
            "id": "pve-9",
            "product": "pve",
            "major": 9,
            "documentationUrl": "https://pve.proxmox.com/pve-docs/",
        }
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            marker = root / "pve-9.version.html"
            marker.write_text('<span id="revnumber">version 9.2.3,</span>')
            drift.assert_documentation_major(source, root, timeout=1)
            marker.write_text('<span id="revnumber">version 10.0,</span>')
            with self.assertRaises(drift.DriftError):
                drift.assert_documentation_major(source, root, timeout=1)

    def test_workflow_is_manual_or_scheduled_read_only_and_secret_free(self) -> None:
        workflow = (
            REPOSITORY / ".github/workflows/proxmox-api-schema-drift.yml"
        ).read_text()
        self.assertIn("  workflow_dispatch:", workflow)
        self.assertIn("  schedule:", workflow)
        self.assertIn("  contents: read", workflow)
        self.assertNotIn("  pull_request:", workflow)
        self.assertNotIn("  push:", workflow)
        self.assertNotIn("secrets.", workflow)
        self.assertIn("scripts/check-proxmox-api-schema-drift.py", workflow)


if __name__ == "__main__":
    unittest.main()
