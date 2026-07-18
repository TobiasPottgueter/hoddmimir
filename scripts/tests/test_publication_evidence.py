from __future__ import annotations

import copy
import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "scripts" / "ci" / "publication_evidence.py"
SPEC = importlib.util.spec_from_file_location("publication_evidence_under_test", MODULE_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Cannot load publication evidence module.")
PUBLICATION = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = PUBLICATION
SPEC.loader.exec_module(PUBLICATION)

REPOSITORY = "ghcr.io/example/hoddmimir"
IMAGE_TAG = "2.0.0-phase7.test"
SOURCE_REF = "refs/heads/codex/test"
SOURCE_SHA = "a" * 40
IMAGE_MANIFEST = "application/vnd.oci.image.manifest.v1+json"
IMAGE_CONFIG = "application/vnd.oci.image.config.v1+json"
IMAGE_INDEX = "application/vnd.oci.image.index.v1+json"


def raw_json(value: dict[str, Any]) -> bytes:
    return json.dumps(value, separators=(",", ":"), sort_keys=True).encode("utf-8")


def raw_digest(raw: bytes) -> str:
    return f"sha256:{hashlib.sha256(raw).hexdigest()}"


def fake_digest(character: str) -> str:
    return "sha256:" + character * 64


def descriptor(raw: bytes, *, platform: dict[str, str], annotations: dict[str, str] | None = None) -> dict[str, Any]:
    result: dict[str, Any] = {
        "digest": raw_digest(raw),
        "mediaType": IMAGE_MANIFEST,
        "platform": platform,
        "size": len(raw),
    }
    if annotations is not None:
        result["annotations"] = annotations
    return result


def build_fixture(
    *,
    source_sha: str = SOURCE_SHA,
    platform: dict[str, str] | None = None,
    attestation_reference: str | None = None,
    extra_descriptors: list[dict[str, Any]] | None = None,
    omit_attestation_annotations: bool = False,
) -> dict[str, Any]:
    config_raw = raw_json(
        {
            "architecture": "amd64",
            "config": {
                "Labels": {
                    "org.opencontainers.image.revision": source_sha,
                    "org.opencontainers.image.source": "https://github.com/example/hoddmimir",
                }
            },
            "created": "2026-07-18T00:00:00Z",
            "history": [],
            "os": "linux",
            "rootfs": {"diff_ids": [], "type": "layers"},
        }
    )
    platform_manifest_raw = raw_json(
        {
            "config": {
                "digest": raw_digest(config_raw),
                "mediaType": IMAGE_CONFIG,
                "size": len(config_raw),
            },
            "layers": [],
            "mediaType": IMAGE_MANIFEST,
            "schemaVersion": 2,
        }
    )
    platform_descriptor = descriptor(
        platform_manifest_raw,
        platform=platform or {"architecture": "amd64", "os": "linux"},
    )
    attestation_manifest_raw = raw_json(
        {
            "config": {
                "digest": fake_digest("b"),
                "mediaType": IMAGE_CONFIG,
                "size": 241,
            },
            "layers": [
                {
                    "annotations": {"in-toto.io/predicate-type": "https://slsa.dev/provenance/v1"},
                    "digest": fake_digest("c"),
                    "mediaType": "application/vnd.in-toto+json",
                    "size": 34903,
                }
            ],
            "mediaType": IMAGE_MANIFEST,
            "schemaVersion": 2,
        }
    )
    annotations = None
    if not omit_attestation_annotations:
        annotations = {
            "vnd.docker.reference.digest": attestation_reference or platform_descriptor["digest"],
            "vnd.docker.reference.type": "attestation-manifest",
        }
    attestation_descriptor = descriptor(
        attestation_manifest_raw,
        platform={"architecture": "unknown", "os": "unknown"},
        annotations=annotations,
    )
    manifests = [platform_descriptor, attestation_descriptor]
    manifests.extend(extra_descriptors or [])
    index_raw = raw_json(
        {
            "manifests": manifests,
            "mediaType": IMAGE_INDEX,
            "schemaVersion": 2,
        }
    )
    return {
        "attestation_descriptor": attestation_descriptor,
        "attestation_manifests": {attestation_descriptor["digest"]: attestation_manifest_raw},
        "config_raw": config_raw,
        "index_raw": index_raw,
        "platform_descriptor": platform_descriptor,
        "platform_manifest_raw": platform_manifest_raw,
        "registry_digest": raw_digest(index_raw),
    }


def create(target: str, fixture: dict[str, Any] | None = None) -> dict[str, Any]:
    selected = fixture or build_fixture()
    return PUBLICATION.create_image_evidence(
        target=target,
        image=f"{REPOSITORY}/{target}",
        repository=REPOSITORY,
        image_tag=IMAGE_TAG,
        source_ref=SOURCE_REF,
        source_sha=SOURCE_SHA,
        registry_digest=selected["registry_digest"],
        index_raw=selected["index_raw"],
        platform_manifest_raw=selected["platform_manifest_raw"],
        config_raw=selected["config_raw"],
        attestation_manifests=selected["attestation_manifests"],
    )


class PerImageEvidenceTest(unittest.TestCase):
    def test_accepts_realistic_digestless_index_and_bound_buildx_attestation(self) -> None:
        fixture = build_fixture()
        self.assertNotIn("digest", json.loads(fixture["index_raw"]))

        result = create("worker", fixture)

        self.assertEqual(fixture["registry_digest"], result["registry"]["digest"])
        self.assertEqual(fixture["platform_descriptor"]["digest"], result["platform"]["digest"])
        self.assertEqual(
            fixture["platform_descriptor"]["digest"],
            result["platform"]["attestations"][0]["referenceDigest"],
        )

    def test_rejects_registry_digest_not_matching_exact_index_bytes(self) -> None:
        fixture = build_fixture()
        fixture["registry_digest"] = fake_digest("9")
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "index bytes"):
            create("worker", fixture)

    def test_rejects_wrong_or_additional_application_architecture(self) -> None:
        for architecture in ("arm64", "ppc64le"):
            with self.subTest(architecture=architecture):
                extra = descriptor(
                    b"{}",
                    platform={"architecture": architecture, "os": "linux"},
                )
                fixture = build_fixture(extra_descriptors=[extra])
                with self.assertRaisesRegex(PUBLICATION.EvidenceError, "non-release application platform"):
                    create("worker", fixture)

    def test_rejects_missing_or_duplicate_amd64_manifest_even_with_shared_config(self) -> None:
        missing = build_fixture(platform={"architecture": "arm64", "os": "linux"})
        with self.assertRaises(PUBLICATION.EvidenceError):
            create("worker", missing)

        base = build_fixture()
        duplicate = copy.deepcopy(base["platform_descriptor"])
        fixture = build_fixture(extra_descriptors=[duplicate])
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "exactly one linux/amd64"):
            create("worker", fixture)

    def test_rejects_platform_manifest_or_config_not_matching_descriptor(self) -> None:
        fixture = build_fixture()
        fixture["platform_manifest_raw"] += b" "
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "application manifest bytes"):
            create("worker", fixture)

        fixture = build_fixture()
        fixture["config_raw"] += b" "
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "config blob bytes"):
            create("worker", fixture)

    def test_rejects_unbound_or_application_shaped_unknown_descriptor(self) -> None:
        fixture = build_fixture(attestation_reference=fake_digest("e"))
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "not bound"):
            create("worker", fixture)

        fixture = build_fixture(omit_attestation_annotations=True)
        with self.assertRaises(PUBLICATION.EvidenceError):
            create("worker", fixture)

    def test_rejects_attestation_manifest_not_matching_descriptor(self) -> None:
        fixture = build_fixture()
        attestation_digest = fixture["attestation_descriptor"]["digest"]
        fixture["attestation_manifests"][attestation_digest] += b" "
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "attestation manifest 0 bytes"):
            create("worker", fixture)

    def test_rejects_source_label_mismatch(self) -> None:
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "revision label"):
            create("worker", build_fixture(source_sha="b" * 40))

    def test_rejects_upstream_mariadb_instead_of_project_image(self) -> None:
        fixture = build_fixture()
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "must be exactly"):
            PUBLICATION.create_image_evidence(
                target="mariadb",
                image="ghcr.io/library/mariadb",
                repository=REPOSITORY,
                image_tag=IMAGE_TAG,
                source_ref=SOURCE_REF,
                source_sha=SOURCE_SHA,
                registry_digest=fixture["registry_digest"],
                index_raw=fixture["index_raw"],
                platform_manifest_raw=fixture["platform_manifest_raw"],
                config_raw=fixture["config_raw"],
                attestation_manifests=fixture["attestation_manifests"],
            )


class CombinedEvidenceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.evidences = [create("worker"), create("web"), create("mariadb")]
        for index, evidence in enumerate(self.evidences, start=1):
            evidence["registry"]["digest"] = fake_digest(str(index))
            evidence["registry"]["reference"] = f"{evidence['image']}@{fake_digest(str(index))}"
            evidence["platform"]["digest"] = fake_digest(str(index + 3))
            evidence["platform"]["reference"] = f"{evidence['image']}@{fake_digest(str(index + 3))}"
            for attestation in evidence["platform"]["attestations"]:
                attestation["referenceDigest"] = fake_digest(str(index + 3))

    def combine(self, evidences: list[dict[str, Any]] | None = None) -> dict[str, Any]:
        return PUBLICATION.combine_evidence(
            self.evidences if evidences is None else evidences,
            repository=REPOSITORY,
            image_tag=IMAGE_TAG,
            source_ref=SOURCE_REF,
            source_sha=SOURCE_SHA,
        )

    def test_exports_exact_three_images_and_four_platform_deployment_references(self) -> None:
        combined = self.combine()

        self.assertEqual({"mariadb", "web", "worker"}, set(combined["images"]))
        deployment = combined["deployment"]
        self.assertEqual(deployment["hoddmimir_data_worker_image"], deployment["hoddmimir_backup_worker_image"])
        self.assertEqual(self.evidences[0]["platform"]["reference"], deployment["hoddmimir_data_worker_image"])
        self.assertEqual("linux/amd64", deployment["hoddmimir_release_platform"])

    def test_rejects_not_published_mariadb_or_duplicate_target(self) -> None:
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "exactly 3"):
            self.combine(self.evidences[:2])
        duplicate = [self.evidences[0], self.evidences[1], self.evidences[1]]
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "without duplicates"):
            self.combine(duplicate)

    def test_rejects_duplicate_index_or_platform_digests(self) -> None:
        evidences = copy.deepcopy(self.evidences)
        evidences[1]["registry"]["digest"] = evidences[0]["registry"]["digest"]
        evidences[1]["registry"]["reference"] = f"{evidences[1]['image']}@{evidences[0]['registry']['digest']}"
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "index digests must be mutually unique"):
            self.combine(evidences)

        evidences = copy.deepcopy(self.evidences)
        evidences[1]["platform"]["digest"] = evidences[0]["platform"]["digest"]
        evidences[1]["platform"]["reference"] = f"{evidences[1]['image']}@{evidences[0]['platform']['digest']}"
        evidences[1]["platform"]["attestations"][0]["referenceDigest"] = evidences[0]["platform"]["digest"]
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "platform digests must be mutually unique"):
            self.combine(evidences)

    def test_rejects_platform_digest_equal_to_any_foreign_index_digest(self) -> None:
        evidences = copy.deepcopy(self.evidences)
        foreign_index = evidences[1]["registry"]["digest"]
        evidences[0]["platform"]["digest"] = foreign_index
        evidences[0]["platform"]["reference"] = f"{evidences[0]['image']}@{foreign_index}"
        evidences[0]["platform"]["attestations"][0]["referenceDigest"] = foreign_index
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "any of the three OCI index digests"):
            self.combine(evidences)

    def test_rejects_missing_or_invalid_platform_digest(self) -> None:
        for mutation in (None, fake_digest("A")):
            with self.subTest(mutation=mutation):
                evidences = copy.deepcopy(self.evidences)
                if mutation is None:
                    del evidences[0]["platform"]["digest"]
                else:
                    evidences[0]["platform"]["digest"] = mutation
                with self.assertRaises(PUBLICATION.EvidenceError):
                    self.combine(evidences)

    def test_rejects_combined_architecture_drift_and_upstream_mariadb(self) -> None:
        evidences = copy.deepcopy(self.evidences)
        evidences[0]["platform"]["architecture"] = "arm64"
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "not linux/amd64"):
            self.combine(evidences)

        evidences = copy.deepcopy(self.evidences)
        evidences[2]["image"] = "ghcr.io/library/mariadb"
        with self.assertRaisesRegex(PUBLICATION.EvidenceError, "must be exactly"):
            self.combine(evidences)


if __name__ == "__main__":
    unittest.main()
