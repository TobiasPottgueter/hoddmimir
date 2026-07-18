#!/usr/bin/env python3

"""Fetch, build, and validate closed Hoddmímir publication evidence."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, NoReturn


SCHEMA_VERSION = 2
RELEASE_OS = "linux"
RELEASE_ARCHITECTURE = "amd64"
TARGETS = ("mariadb", "web", "worker")
DIGEST_PATTERN = re.compile(r"^sha256:[0-9a-f]{64}$")
SHA_PATTERN = re.compile(r"^[0-9a-f]{40}$")
TAG_PATTERN = re.compile(r"^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$")
IMAGE_PATTERN = re.compile(
    r"^ghcr\.io/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?"
    r"(?:/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?)+$"
)
INDEX_MEDIA_TYPE = "application/vnd.oci.image.index.v1+json"
IMAGE_MANIFEST_MEDIA_TYPE = "application/vnd.oci.image.manifest.v1+json"
IMAGE_CONFIG_MEDIA_TYPE = "application/vnd.oci.image.config.v1+json"
BUILDX_REFERENCE_DIGEST = "vnd.docker.reference.digest"
BUILDX_REFERENCE_TYPE = "vnd.docker.reference.type"
BUILDX_ATTESTATION_TYPE = "attestation-manifest"


class EvidenceError(ValueError):
    """Raised when publication evidence is incomplete or contradictory."""


def fail(message: str) -> NoReturn:
    raise EvidenceError(message)


def require_exact_keys(value: dict[str, Any], expected: set[str], context: str) -> None:
    actual = set(value)
    if actual != expected:
        fail(f"{context} must have exactly {sorted(expected)}; got {sorted(actual)}")


def require_string(value: Any, context: str) -> str:
    if not isinstance(value, str) or not value:
        fail(f"{context} must be a non-empty string")
    return value


def require_digest(value: Any, context: str) -> str:
    digest = require_string(value, context)
    if DIGEST_PATTERN.fullmatch(digest) is None:
        fail(f"{context} must be a lowercase sha256 digest")
    return digest


def require_positive_size(value: Any, context: str) -> int:
    if not isinstance(value, int) or isinstance(value, bool) or value <= 0:
        fail(f"{context} must be a positive integer")
    return value


def require_source(source_ref: str, source_sha: str) -> None:
    if not source_ref.startswith("refs/") or any(character.isspace() for character in source_ref):
        fail("source ref must be one non-empty refs/* value without whitespace")
    if SHA_PATTERN.fullmatch(source_sha) is None:
        fail("source SHA must contain exactly 40 lowercase hexadecimal characters")


def require_target_image(target: str, image: str, repository: str) -> None:
    if target not in TARGETS:
        fail(f"target must be one of {list(TARGETS)}")
    if IMAGE_PATTERN.fullmatch(repository) is None:
        fail("repository must be one canonical lowercase GHCR repository path")
    expected = f"{repository}/{target}"
    if image != expected:
        fail(f"{target} image must be exactly {expected}")


def parse_object(raw: bytes, context: str) -> dict[str, Any]:
    try:
        value = json.loads(raw)
    except (UnicodeError, json.JSONDecodeError) as error:
        fail(f"{context} is not valid UTF-8 JSON: {error}")
    if not isinstance(value, dict):
        fail(f"{context} must be one JSON object")
    return value


def load_object(path: Path, context: str) -> dict[str, Any]:
    try:
        return parse_object(path.read_bytes(), context)
    except OSError as error:
        fail(f"cannot read {context}: {error}")


def sha256_digest(raw: bytes) -> str:
    return f"sha256:{hashlib.sha256(raw).hexdigest()}"


def verify_content(
    raw: bytes,
    *,
    digest: str,
    size: int | None,
    context: str,
) -> None:
    if sha256_digest(raw) != digest:
        fail(f"{context} bytes do not match their sha256 digest")
    if size is not None and len(raw) != size:
        fail(f"{context} bytes do not match their descriptor size")


def require_descriptor(
    descriptor: dict[str, Any],
    *,
    context: str,
    annotations: bool,
) -> tuple[str, int]:
    keys = {"digest", "mediaType", "platform", "size"}
    if annotations:
        keys.add("annotations")
    require_exact_keys(descriptor, keys, context)
    if descriptor["mediaType"] != IMAGE_MANIFEST_MEDIA_TYPE:
        fail(f"{context} is not an OCI image manifest")
    return (
        require_digest(descriptor["digest"], f"{context} digest"),
        require_positive_size(descriptor["size"], f"{context} size"),
    )


def validate_attestation_manifest(
    raw: bytes,
    *,
    descriptor: dict[str, Any],
    platform_descriptor: dict[str, Any],
    context: str,
) -> dict[str, Any]:
    digest, size = require_descriptor(descriptor, context=context, annotations=True)
    platform = descriptor["platform"]
    if platform != {"architecture": "unknown", "os": "unknown"}:
        fail(f"{context} must use the exact Buildx unknown/unknown platform marker")
    annotations = descriptor["annotations"]
    if not isinstance(annotations, dict):
        fail(f"{context} annotations must be an object")
    require_exact_keys(
        annotations,
        {BUILDX_REFERENCE_DIGEST, BUILDX_REFERENCE_TYPE},
        f"{context} annotations",
    )
    platform_digest = require_digest(platform_descriptor["digest"], "linux/amd64 platform digest")
    if annotations[BUILDX_REFERENCE_DIGEST] != platform_digest:
        fail(f"{context} is not bound to the selected linux/amd64 manifest")
    if annotations[BUILDX_REFERENCE_TYPE] != BUILDX_ATTESTATION_TYPE:
        fail(f"{context} is not an expected Buildx attestation manifest")

    verify_content(raw, digest=digest, size=size, context=context)
    manifest = parse_object(raw, context)
    require_exact_keys(
        manifest,
        {"config", "layers", "mediaType", "schemaVersion"},
        context,
    )
    if manifest["schemaVersion"] != 2 or manifest["mediaType"] != IMAGE_MANIFEST_MEDIA_TYPE:
        fail(f"{context} has an invalid OCI manifest envelope")
    config = manifest["config"]
    if not isinstance(config, dict):
        fail(f"{context} config descriptor must be an object")
    require_exact_keys(config, {"digest", "mediaType", "size"}, f"{context} config descriptor")
    require_digest(config["digest"], f"{context} config digest")
    require_positive_size(config["size"], f"{context} config size")
    if config["mediaType"] != IMAGE_CONFIG_MEDIA_TYPE:
        fail(f"{context} config descriptor has an invalid media type")
    layers = manifest["layers"]
    if not isinstance(layers, list) or not layers:
        fail(f"{context} must contain at least one attestation layer")
    for layer_index, layer in enumerate(layers):
        if not isinstance(layer, dict):
            fail(f"{context} layer {layer_index} must be an object")
        require_exact_keys(
            layer,
            {"annotations", "digest", "mediaType", "size"},
            f"{context} layer {layer_index}",
        )
        if layer["mediaType"] != "application/vnd.in-toto+json":
            fail(f"{context} layer {layer_index} is not an in-toto attestation")
        require_digest(layer["digest"], f"{context} layer {layer_index} digest")
        require_positive_size(layer["size"], f"{context} layer {layer_index} size")
        layer_annotations = layer["annotations"]
        if not isinstance(layer_annotations, dict):
            fail(f"{context} layer {layer_index} annotations must be an object")
        require_exact_keys(
            layer_annotations,
            {"in-toto.io/predicate-type"},
            f"{context} layer {layer_index} annotations",
        )
        require_string(
            layer_annotations["in-toto.io/predicate-type"],
            f"{context} layer {layer_index} predicate type",
        )

    return {
        "mediaType": IMAGE_MANIFEST_MEDIA_TYPE,
        "digest": digest,
        "size": size,
        "referenceDigest": platform_digest,
        "referenceType": BUILDX_ATTESTATION_TYPE,
    }


def create_image_evidence(
    *,
    target: str,
    image: str,
    repository: str,
    image_tag: str,
    source_ref: str,
    source_sha: str,
    registry_digest: str,
    index_raw: bytes,
    platform_manifest_raw: bytes,
    config_raw: bytes,
    attestation_manifests: dict[str, bytes],
) -> dict[str, Any]:
    require_target_image(target, image, repository)
    if TAG_PATTERN.fullmatch(image_tag) is None:
        fail("image tag has an invalid OCI tag shape")
    require_source(source_ref, source_sha)
    registry_digest = require_digest(registry_digest, "registry digest")
    verify_content(index_raw, digest=registry_digest, size=None, context="OCI image index")

    index_document = parse_object(index_raw, "OCI image index")
    require_exact_keys(
        index_document,
        {"manifests", "mediaType", "schemaVersion"},
        "OCI image index",
    )
    if index_document["schemaVersion"] != 2 or index_document["mediaType"] != INDEX_MEDIA_TYPE:
        fail("registry reference must resolve to one OCI image index")
    manifests = index_document["manifests"]
    if not isinstance(manifests, list) or len(manifests) < 2:
        fail("OCI image index must contain one application manifest and at least one attestation")

    release_manifests: list[dict[str, Any]] = []
    attestation_descriptors: list[dict[str, Any]] = []
    for descriptor_index, descriptor in enumerate(manifests):
        if not isinstance(descriptor, dict):
            fail(f"manifest descriptor {descriptor_index} must be an object")
        platform = descriptor.get("platform")
        if not isinstance(platform, dict):
            fail(f"manifest descriptor {descriptor_index} platform must be an object")
        architecture = platform.get("architecture")
        operating_system = platform.get("os")
        if architecture == "unknown" and operating_system == "unknown":
            attestation_descriptors.append(descriptor)
            continue
        require_descriptor(
            descriptor,
            context=f"application manifest descriptor {descriptor_index}",
            annotations=False,
        )
        if platform != {"architecture": RELEASE_ARCHITECTURE, "os": RELEASE_OS}:
            fail(
                "OCI image index contains a non-release application platform: "
                f"{operating_system}/{architecture}"
            )
        release_manifests.append(descriptor)

    if len(release_manifests) != 1:
        fail("OCI image index must contain exactly one linux/amd64 application manifest")
    if not attestation_descriptors:
        fail("OCI image index must contain at least one bound Buildx attestation manifest")

    platform_descriptor = release_manifests[0]
    platform_digest, platform_size = require_descriptor(
        platform_descriptor,
        context="linux/amd64 application manifest descriptor",
        annotations=False,
    )
    if platform_digest == registry_digest:
        fail("registry index digest and linux/amd64 manifest digest must be distinct")
    verify_content(
        platform_manifest_raw,
        digest=platform_digest,
        size=platform_size,
        context="linux/amd64 application manifest",
    )
    platform_manifest = parse_object(platform_manifest_raw, "linux/amd64 application manifest")
    require_exact_keys(
        platform_manifest,
        {"config", "layers", "mediaType", "schemaVersion"},
        "linux/amd64 application manifest",
    )
    if platform_manifest["schemaVersion"] != 2 or platform_manifest["mediaType"] != IMAGE_MANIFEST_MEDIA_TYPE:
        fail("linux/amd64 application manifest has an invalid OCI envelope")

    config_descriptor = platform_manifest["config"]
    if not isinstance(config_descriptor, dict):
        fail("application config descriptor must be an object")
    require_exact_keys(
        config_descriptor,
        {"digest", "mediaType", "size"},
        "application config descriptor",
    )
    config_digest = require_digest(config_descriptor["digest"], "application config digest")
    config_size = require_positive_size(config_descriptor["size"], "application config size")
    if config_descriptor["mediaType"] != IMAGE_CONFIG_MEDIA_TYPE:
        fail("application config descriptor has an invalid media type")
    verify_content(config_raw, digest=config_digest, size=config_size, context="application config blob")
    image_config = parse_object(config_raw, "application config blob")
    if image_config.get("architecture") != RELEASE_ARCHITECTURE or image_config.get("os") != RELEASE_OS:
        fail("resolved image configuration is not linux/amd64")
    config = image_config.get("config")
    if not isinstance(config, dict):
        fail("image configuration config must be an object")
    labels = config.get("Labels")
    if not isinstance(labels, dict):
        fail("image labels must be an object")
    if labels.get("org.opencontainers.image.revision") != source_sha:
        fail("image revision label does not match the dispatched source SHA")
    expected_source = f"https://github.com/{repository.removeprefix('ghcr.io/')}"
    actual_source = labels.get("org.opencontainers.image.source")
    if not isinstance(actual_source, str) or actual_source.casefold() != expected_source.casefold():
        fail("image source label does not match the dispatched repository")

    expected_attestation_digests = {
        require_digest(descriptor.get("digest"), "attestation descriptor digest")
        for descriptor in attestation_descriptors
    }
    if set(attestation_manifests) != expected_attestation_digests:
        fail("fetched attestation manifests do not exactly match the OCI index")
    attestations = [
        validate_attestation_manifest(
            attestation_manifests[require_digest(descriptor["digest"], "attestation descriptor digest")],
            descriptor=descriptor,
            platform_descriptor=platform_descriptor,
            context=f"Buildx attestation manifest {index}",
        )
        for index, descriptor in enumerate(attestation_descriptors)
    ]

    return {
        "schemaVersion": SCHEMA_VERSION,
        "target": target,
        "image": image,
        "tag": f"{image}:{image_tag}",
        "source": {"ref": source_ref, "sha": source_sha},
        "registry": {
            "mediaType": INDEX_MEDIA_TYPE,
            "digest": registry_digest,
            "reference": f"{image}@{registry_digest}",
        },
        "platform": {
            "mediaType": IMAGE_MANIFEST_MEDIA_TYPE,
            "os": RELEASE_OS,
            "architecture": RELEASE_ARCHITECTURE,
            "digest": platform_digest,
            "size": platform_size,
            "reference": f"{image}@{platform_digest}",
            "config": {
                "mediaType": IMAGE_CONFIG_MEDIA_TYPE,
                "digest": config_digest,
                "size": config_size,
            },
            "attestations": attestations,
        },
    }


class AnonymousRegistryClient:
    """Read one public GHCR repository without consulting Docker credentials."""

    def __init__(self, image: str) -> None:
        if not image.startswith("ghcr.io/"):
            fail("anonymous publication verification only supports ghcr.io")
        self._host = "ghcr.io"
        self._repository = image.removeprefix("ghcr.io/")
        self._base = f"https://{self._host}/v2/{self._repository}"
        self._token: str | None = None

    def fetch_manifest(self, digest: str, *, expected_media_type: str) -> bytes:
        raw, headers = self._fetch(
            f"{self._base}/manifests/{require_digest(digest, 'manifest digest')}",
            accept=expected_media_type,
        )
        self._validate_response_headers(headers, digest=digest, expected_media_type=expected_media_type)
        return raw

    def fetch_blob(self, digest: str) -> bytes:
        raw, headers = self._fetch(
            f"{self._base}/blobs/{require_digest(digest, 'blob digest')}",
            accept="application/octet-stream",
        )
        response_digest = headers.get("Docker-Content-Digest")
        if response_digest is not None and response_digest != digest:
            fail("registry blob response digest does not match the requested digest")
        return raw

    def _fetch(self, url: str, *, accept: str) -> tuple[bytes, Any]:
        request = urllib.request.Request(url, headers={"Accept": accept, "User-Agent": "hoddmimir-publication-evidence/2"})
        if self._token is not None:
            request.add_header("Authorization", f"Bearer {self._token}")
        try:
            with urllib.request.urlopen(request, timeout=30) as response:
                return response.read(), response.headers
        except urllib.error.HTTPError as error:
            if error.code != 401 or self._token is not None:
                fail(f"anonymous registry read failed with HTTP {error.code}")
            challenge = error.headers.get("WWW-Authenticate")
            self._token = self._request_public_token(challenge)
            return self._fetch(url, accept=accept)
        except (OSError, urllib.error.URLError) as error:
            fail(f"anonymous registry read failed: {error}")

    def _request_public_token(self, challenge: str | None) -> str:
        if challenge is None or not challenge.startswith("Bearer "):
            fail("registry did not provide a supported anonymous Bearer challenge")
        parameters = urllib.request.parse_keqv_list(
            urllib.request.parse_http_list(challenge.removeprefix("Bearer "))
        )
        realm = parameters.get("realm")
        service = parameters.get("service")
        scope = parameters.get("scope")
        expected_scope = f"repository:{self._repository}:pull"
        if not isinstance(realm, str) or not isinstance(service, str) or scope != expected_scope:
            fail("registry Bearer challenge is not limited to the expected public pull scope")
        realm_url = urllib.parse.urlsplit(realm)
        if realm_url.scheme != "https" or realm_url.hostname != self._host:
            fail("registry Bearer challenge uses an unexpected token service")
        token_url = f"{realm}?{urllib.parse.urlencode({'service': service, 'scope': scope})}"
        try:
            with urllib.request.urlopen(
                urllib.request.Request(token_url, headers={"User-Agent": "hoddmimir-publication-evidence/2"}),
                timeout=30,
            ) as response:
                payload = json.loads(response.read())
        except (OSError, urllib.error.URLError, json.JSONDecodeError) as error:
            fail(f"cannot obtain anonymous registry token: {error}")
        if not isinstance(payload, dict):
            fail("anonymous registry token response must be an object")
        token = payload.get("token", payload.get("access_token"))
        return require_string(token, "anonymous registry token")

    @staticmethod
    def _validate_response_headers(headers: Any, *, digest: str, expected_media_type: str) -> None:
        response_digest = headers.get("Docker-Content-Digest")
        if response_digest is not None and response_digest != digest:
            fail("registry manifest response digest does not match the requested digest")
        content_type = headers.get_content_type()
        if content_type != expected_media_type:
            fail("registry manifest response media type does not match the expected OCI type")


def fetch_image_evidence(
    *,
    target: str,
    image: str,
    repository: str,
    image_tag: str,
    source_ref: str,
    source_sha: str,
    registry_digest: str,
) -> dict[str, Any]:
    require_target_image(target, image, repository)
    client = AnonymousRegistryClient(image)
    index_raw = client.fetch_manifest(registry_digest, expected_media_type=INDEX_MEDIA_TYPE)
    index_document = parse_object(index_raw, "OCI image index")
    descriptors = index_document.get("manifests")
    if not isinstance(descriptors, list):
        fail("OCI image index manifests must be a list")
    platform_descriptors = [
        descriptor
        for descriptor in descriptors
        if isinstance(descriptor, dict)
        and descriptor.get("platform") == {"architecture": RELEASE_ARCHITECTURE, "os": RELEASE_OS}
    ]
    if len(platform_descriptors) != 1:
        fail("OCI image index must identify exactly one linux/amd64 application manifest")
    platform_digest = require_digest(platform_descriptors[0].get("digest"), "linux/amd64 platform digest")
    platform_manifest_raw = client.fetch_manifest(
        platform_digest,
        expected_media_type=IMAGE_MANIFEST_MEDIA_TYPE,
    )
    platform_manifest = parse_object(platform_manifest_raw, "linux/amd64 application manifest")
    config_descriptor = platform_manifest.get("config")
    if not isinstance(config_descriptor, dict):
        fail("application config descriptor must be an object")
    config_digest = require_digest(config_descriptor.get("digest"), "application config digest")
    config_raw = client.fetch_blob(config_digest)
    attestation_manifests: dict[str, bytes] = {}
    for descriptor in descriptors:
        if not isinstance(descriptor, dict):
            continue
        if descriptor.get("platform") != {"architecture": "unknown", "os": "unknown"}:
            continue
        digest = require_digest(descriptor.get("digest"), "attestation descriptor digest")
        attestation_manifests[digest] = client.fetch_manifest(
            digest,
            expected_media_type=IMAGE_MANIFEST_MEDIA_TYPE,
        )
    return create_image_evidence(
        target=target,
        image=image,
        repository=repository,
        image_tag=image_tag,
        source_ref=source_ref,
        source_sha=source_sha,
        registry_digest=registry_digest,
        index_raw=index_raw,
        platform_manifest_raw=platform_manifest_raw,
        config_raw=config_raw,
        attestation_manifests=attestation_manifests,
    )


def validate_image_evidence(
    evidence: dict[str, Any],
    *,
    repository: str,
    image_tag: str,
    source_ref: str,
    source_sha: str,
) -> dict[str, Any]:
    require_exact_keys(
        evidence,
        {"schemaVersion", "target", "image", "tag", "source", "registry", "platform"},
        "per-image evidence",
    )
    if evidence["schemaVersion"] != SCHEMA_VERSION:
        fail("per-image evidence schema version is unsupported")
    target = require_string(evidence["target"], "target")
    image = require_string(evidence["image"], "image")
    require_target_image(target, image, repository)
    if evidence["tag"] != f"{image}:{image_tag}":
        fail("per-image tag does not match the requested release tag")

    source = evidence["source"]
    if not isinstance(source, dict):
        fail("per-image source must be an object")
    require_exact_keys(source, {"ref", "sha"}, "per-image source")
    if source != {"ref": source_ref, "sha": source_sha}:
        fail("per-image source identity does not match the dispatched source")

    registry = evidence["registry"]
    if not isinstance(registry, dict):
        fail("per-image registry evidence must be an object")
    require_exact_keys(registry, {"mediaType", "digest", "reference"}, "per-image registry evidence")
    if registry["mediaType"] != INDEX_MEDIA_TYPE:
        fail("per-image registry evidence is not an OCI image index")
    registry_digest = require_digest(registry["digest"], "registry digest")
    if registry["reference"] != f"{image}@{registry_digest}":
        fail("registry reference does not bind the validated registry digest")

    platform = evidence["platform"]
    if not isinstance(platform, dict):
        fail("per-image platform evidence must be an object")
    require_exact_keys(
        platform,
        {
            "architecture",
            "attestations",
            "config",
            "digest",
            "mediaType",
            "os",
            "reference",
            "size",
        },
        "per-image platform evidence",
    )
    if platform["mediaType"] != IMAGE_MANIFEST_MEDIA_TYPE:
        fail("per-image platform evidence is not an OCI image manifest")
    if platform["os"] != RELEASE_OS or platform["architecture"] != RELEASE_ARCHITECTURE:
        fail("per-image evidence platform is not linux/amd64")
    platform_digest = require_digest(platform["digest"], "platform digest")
    require_positive_size(platform["size"], "platform size")
    if platform["reference"] != f"{image}@{platform_digest}":
        fail("platform reference does not bind the validated platform digest")
    if platform_digest == registry_digest:
        fail("registry and platform digests must be distinct")
    config = platform["config"]
    if not isinstance(config, dict):
        fail("platform config evidence must be an object")
    require_exact_keys(config, {"digest", "mediaType", "size"}, "platform config evidence")
    if config["mediaType"] != IMAGE_CONFIG_MEDIA_TYPE:
        fail("platform config evidence has an invalid media type")
    require_digest(config["digest"], "platform config digest")
    require_positive_size(config["size"], "platform config size")
    attestations = platform["attestations"]
    if not isinstance(attestations, list) or not attestations:
        fail("platform evidence must contain at least one attestation")
    attestation_digests: set[str] = set()
    for index, attestation in enumerate(attestations):
        if not isinstance(attestation, dict):
            fail(f"attestation evidence {index} must be an object")
        require_exact_keys(
            attestation,
            {"digest", "mediaType", "referenceDigest", "referenceType", "size"},
            f"attestation evidence {index}",
        )
        if attestation["mediaType"] != IMAGE_MANIFEST_MEDIA_TYPE:
            fail(f"attestation evidence {index} has an invalid media type")
        attestation_digest = require_digest(attestation["digest"], f"attestation evidence {index} digest")
        require_positive_size(attestation["size"], f"attestation evidence {index} size")
        if attestation["referenceDigest"] != platform_digest:
            fail(f"attestation evidence {index} is not bound to the platform digest")
        if attestation["referenceType"] != BUILDX_ATTESTATION_TYPE:
            fail(f"attestation evidence {index} has an invalid reference type")
        if attestation_digest in attestation_digests:
            fail("attestation evidence contains duplicate manifest digests")
        attestation_digests.add(attestation_digest)
    return evidence


def combine_evidence(
    evidences: list[dict[str, Any]],
    *,
    repository: str,
    image_tag: str,
    source_ref: str,
    source_sha: str,
) -> dict[str, Any]:
    if len(evidences) != len(TARGETS):
        fail(f"exactly {len(TARGETS)} per-image evidence documents are required")
    validated = [
        validate_image_evidence(
            evidence,
            repository=repository,
            image_tag=image_tag,
            source_ref=source_ref,
            source_sha=source_sha,
        )
        for evidence in evidences
    ]
    by_target = {evidence["target"]: evidence for evidence in validated}
    if set(by_target) != set(TARGETS) or len(by_target) != len(validated):
        fail(f"evidence targets must be exactly {list(TARGETS)} without duplicates")

    registry_digests = [by_target[target]["registry"]["digest"] for target in TARGETS]
    platform_digests = [by_target[target]["platform"]["digest"] for target in TARGETS]
    if len(set(registry_digests)) != len(TARGETS):
        fail("the three OCI index digests must be mutually unique")
    if len(set(platform_digests)) != len(TARGETS):
        fail("the three linux/amd64 platform digests must be mutually unique")
    if set(registry_digests) & set(platform_digests):
        fail("no runtime platform digest may equal any of the three OCI index digests")

    images: dict[str, Any] = {}
    for target in TARGETS:
        evidence = by_target[target]
        images[target] = {
            "image": evidence["image"],
            "tag": evidence["tag"],
            "registry": evidence["registry"],
            "platform": evidence["platform"],
        }

    worker_reference = images["worker"]["platform"]["reference"]
    return {
        "schemaVersion": SCHEMA_VERSION,
        "releasePlatform": {"os": RELEASE_OS, "architecture": RELEASE_ARCHITECTURE},
        "imageTag": image_tag,
        "source": {"ref": source_ref, "sha": source_sha},
        "images": images,
        "deployment": {
            "hoddmimir_image_repository": repository,
            "hoddmimir_release_platform": f"{RELEASE_OS}/{RELEASE_ARCHITECTURE}",
            "hoddmimir_registry_index_digests": {
                target: images[target]["registry"]["digest"] for target in TARGETS
            },
            "hoddmimir_data_worker_image": worker_reference,
            "hoddmimir_backup_worker_image": worker_reference,
            "hoddmimir_webapp_image": images["web"]["platform"]["reference"],
            "hoddmimir_mariadb_image": images["mariadb"]["platform"]["reference"],
        },
    }


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(value, indent=2, sort_keys=True, ensure_ascii=True) + "\n",
        encoding="utf-8",
    )


def parse_arguments() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    image_parser = subparsers.add_parser("image")
    image_parser.add_argument("--target", required=True)
    image_parser.add_argument("--image", required=True)
    image_parser.add_argument("--repository", required=True)
    image_parser.add_argument("--image-tag", required=True)
    image_parser.add_argument("--source-ref", required=True)
    image_parser.add_argument("--source-sha", required=True)
    image_parser.add_argument("--registry-digest", required=True)
    image_parser.add_argument("--output", required=True, type=Path)

    combine_parser = subparsers.add_parser("combine")
    combine_parser.add_argument("--repository", required=True)
    combine_parser.add_argument("--image-tag", required=True)
    combine_parser.add_argument("--source-ref", required=True)
    combine_parser.add_argument("--source-sha", required=True)
    combine_parser.add_argument("--output", required=True, type=Path)
    combine_parser.add_argument("inputs", nargs="+")
    return parser.parse_args()


def main() -> int:
    arguments = parse_arguments()
    try:
        if arguments.command == "image":
            evidence = fetch_image_evidence(
                target=arguments.target,
                image=arguments.image,
                repository=arguments.repository,
                image_tag=arguments.image_tag,
                source_ref=arguments.source_ref,
                source_sha=arguments.source_sha,
                registry_digest=arguments.registry_digest,
            )
        else:
            evidence = combine_evidence(
                [load_object(Path(path), f"per-image evidence {path}") for path in arguments.inputs],
                repository=arguments.repository,
                image_tag=arguments.image_tag,
                source_ref=arguments.source_ref,
                source_sha=arguments.source_sha,
            )
        write_json(arguments.output, evidence)
    except EvidenceError as error:
        print(f"publication evidence rejected: {error}", file=sys.stderr)
        return 2
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
