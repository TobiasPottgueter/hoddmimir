# syntax=docker/dockerfile:1.7@sha256:a57df69d0ea827fb7266491f2813635de6f17269be881f696fbfdf2d83dda33e

ARG MARIADB_IMAGE=mariadb:11.4.12@sha256:a794d9eb009e20de605858a11f32f63b4075cbd197c650436f0e3b457e4caed7
ARG GOLANG_IMAGE=golang:1.26.5-bookworm@sha256:18aedc16aa19b3fd7ded7245fc14b109e054d65d22ed53c355c899582bbb2113

FROM ${GOLANG_IMAGE} AS gosu-build

ARG GOSU_VERSION=1.19
ARG GOSU_COMMIT=6456aaa0f3c854d199d0f037f068eb97515b7513

RUN git clone https://github.com/tianon/gosu.git /src/gosu \
    && cd /src/gosu \
    && git checkout --detach "${GOSU_COMMIT}" \
    && test "$(git rev-parse HEAD)" = "${GOSU_COMMIT}" \
    && CGO_ENABLED=0 go build -trimpath -ldflags '-d -w' -o /go/bin/gosu . \
    && /go/bin/gosu --version | grep -F "${GOSU_VERSION}"

FROM ${MARIADB_IMAGE}

COPY --from=gosu-build /go/bin/gosu /usr/local/bin/gosu
COPY --chmod=0555 docker/mariadb/init/10-create-app-users.sh /usr/local/bin/hoddmimir-database-user-bootstrap
