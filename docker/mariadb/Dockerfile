ARG MARIADB_IMAGE=mariadb:11.4.12@sha256:a794d9eb009e20de605858a11f32f63b4075cbd197c650436f0e3b457e4caed7

FROM ${MARIADB_IMAGE}

COPY --chmod=0555 docker/mariadb/init/10-create-app-users.sh /docker-entrypoint-initdb.d/10-create-app-users.sh
