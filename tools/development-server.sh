#!/bin/sh
set -eu

watcher_pid=''
server_pid=''
stopping=0

terminate() {
    stopping=1

    if [ -n "$server_pid" ]; then
        kill -TERM "$server_pid" 2>/dev/null || true
    fi
    if [ -n "$watcher_pid" ]; then
        kill -TERM "$watcher_pid" 2>/dev/null || true
    fi
}

cleanup() {
    trap - EXIT INT TERM HUP
    terminate

    if [ -n "$server_pid" ]; then
        wait "$server_pid" 2>/dev/null || true
    fi
    if [ -n "$watcher_pid" ]; then
        wait "$watcher_pid" 2>/dev/null || true
    fi
}

trap terminate INT TERM HUP
trap cleanup EXIT

# Refuse to accept HTTP traffic until the local extension runtime is verified
# and a fresh readiness marker has been published for this process identity.
php bin/kumwe extension:runtime:watch --once

# Keep the short-lived runtime readiness marker current while the development
# server is running. The command retries transient reconciliation failures.
php bin/kumwe extension:runtime:watch --interval=10 &
watcher_pid=$!

# The dedicated router returns false for real files so PHP's built-in server
# serves the committed Vite CSS/JavaScript instead of routing assets to Kumwe.
php -S 0.0.0.0:8080 -t public tools/browser-router.php &
server_pid=$!

while [ "$stopping" -eq 0 ] \
    && kill -0 "$server_pid" 2>/dev/null \
    && kill -0 "$watcher_pid" 2>/dev/null; do
    sleep 1
done

if [ "$stopping" -ne 0 ]; then
    exit 0
fi

status=0
if ! kill -0 "$server_pid" 2>/dev/null; then
    wait "$server_pid" || status=$?
else
    echo 'Kumwe runtime watcher stopped; stopping the development server.' >&2
    wait "$watcher_pid" || status=$?
    if [ "$status" -eq 0 ]; then
        status=1
    fi
fi

exit "$status"
