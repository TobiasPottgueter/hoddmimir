# FrankenPHP dependency lock

The web Dockerfile rebuilds the published FrankenPHP 1.12.7 source from its
digest-pinned official builder. The matching runner retains the PHP ABI and
shared libraries. The Go toolchain is pinned independently; no build toolchain
or source is copied into the production image.
The binary explicitly reserves an 8 MiB thread stack for Symfony container
compilation on musl; the small Alpine default fails on a cold HTTP start.

These files are the builder's `caddy/go.mod` and `caddy/go.sum`, updated for
kin-openapi 0.144.0, x/crypto 0.55.0 or newer selected by the module graph, and
gRPC 1.83.2 (CVE-2026-84445), including its required x/net 0.58.0. Both direct and transitive versions are checked in. The parent
FrankenPHP source remains the immutable builder's `../` replacement. Builds use
`go mod verify` and `-mod=readonly`, rather than resolving updated dependencies
during image creation.

When updating, use a scratch copy of the matching official builder's module,
run `go get` with explicit security-fixed module versions, and export both
files. Review the full dependency diff and run the container security and
browser gates. Follow the [official custom build contract](https://frankenphp.dev/docs/docker/#how-to-install-more-caddy-modules).
