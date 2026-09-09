#!/bin/sh

set -eu

status=${FAKE_INIT_DEV_SECRETS_STATUS:-0}

if [ "$status" -ne 0 ]; then
    printf 'simulated development secret initialization failure\n' >&2
fi

exit "$status"
