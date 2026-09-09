#!/bin/sh

set -eu

test "${1:-}" = '--coverage'
test "${HODDMIMIR_BACKEND_COVERAGE_IMAGE:-}" = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
status=${FAKE_INTEGRATION_COVERAGE_STATUS:-0}
if [ "$status" -eq 0 ]; then
    mkdir -p backend/coverage
    printf 'integration coverage\n' > backend/coverage/integration.cov
    printf '<coverage/>\n' > backend/coverage/integration.clover.xml
fi
exit "$status"
