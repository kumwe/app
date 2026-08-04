#!/usr/bin/env bash

set -Eeuo pipefail

fail() {
    echo "Kumwe deployment probe failed: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command '$1' is unavailable"
}

require_value() {
    local variable_name="$1"
    [[ -n "${!variable_name:-}" ]] || fail "required environment variable '$variable_name' is empty"
}

http_status() {
    curl --silent --show-error --output "$1" --dump-header "$2" --write-out '%{http_code}' "${@:3}"
}

header_value() {
    local header_name="$1"
    local header_file="$2"
    awk -v expected="${header_name,,}" '
        BEGIN { FS = ":" }
        tolower($1) == expected {
            sub(/^[^:]+:[[:space:]]*/, "")
            sub(/\r$/, "")
            print
            exit
        }
    ' "$header_file"
}

csrf_from_html() {
    sed -n 's/.*name="_csrf" value="\([^"]*\)".*/\1/p' "$1" | head -n 1
}

version_from_html() {
    sed -n 's/.*name="version" value="\([0-9][0-9]*\)".*/\1/p' "$1" | head -n 1
}

require_command awk
require_command cmp
require_command curl
require_command date
require_command grep
require_command jq
require_command openssl
require_command sed
require_value KUMWE_PROBE_BASE_URL
require_value KUMWE_PROBE_ADMIN_EMAIL
require_value KUMWE_PROBE_ADMIN_PASSWORD
require_value KUMWE_PROBE_API_TOKEN
require_value KUMWE_PROBE_READ_TOKEN

probe_root="$(mktemp -d)"
cleanup() {
    find "$probe_root" -depth -mindepth 1 -delete
    rmdir "$probe_root" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

base_url="${KUMWE_PROBE_BASE_URL%/}"
user_agent='Kumwe-Deployment-Probe/2'

login_body="$probe_root/login.body"
login_headers="$probe_root/login.headers"
login_status="$(http_status "$login_body" "$login_headers" \
    --request POST \
    --header "User-Agent: $user_agent" \
    --data-urlencode "email=$KUMWE_PROBE_ADMIN_EMAIL" \
    --data-urlencode "password=$KUMWE_PROBE_ADMIN_PASSWORD" \
    "$base_url/administrator/login")"
[[ "$login_status" == 303 ]] || fail "administrator login returned HTTP $login_status"
session_cookie="$(header_value set-cookie "$login_headers" | cut -d';' -f1)"
[[ "$session_cookie" == kumwe_administrator=* ]] || fail 'administrator login did not issue a session cookie'

editor_body="$probe_root/editor.body"
editor_headers="$probe_root/editor.headers"
editor_status="$(http_status "$editor_body" "$editor_headers" \
    --header "User-Agent: $user_agent" --header "Cookie: $session_cookie" \
    "$base_url/administrator/content/new")"
[[ "$editor_status" == 200 ]] || fail "administrator editor returned HTTP $editor_status"
csrf_token="$(csrf_from_html "$editor_body")"
[[ -n "$csrf_token" ]] || fail 'administrator editor did not render a CSRF token'

csrf_denied_status="$(http_status "$probe_root/csrf.body" "$probe_root/csrf.headers" \
    --request POST \
    --header "User-Agent: $user_agent" --header "Cookie: $session_cookie" \
    --data-urlencode 'title=Rejected request' \
    --data-urlencode 'slug=csrf-rejected' \
    --data-urlencode 'data={"body":"must not be stored"}' \
    "$base_url/administrator/content")"
[[ "$csrf_denied_status" == 403 ]] || fail "missing CSRF token returned HTTP $csrf_denied_status instead of 403"

unique_suffix="$(date -u +%s)-$$"
page_slug="deployment-probe-$unique_suffix"
page_marker="Kumwe deployment probe $unique_suffix"
create_status="$(http_status "$probe_root/create.body" "$probe_root/create.headers" \
    --request POST \
    --header "User-Agent: $user_agent" --header "Cookie: $session_cookie" \
    --data-urlencode "_csrf=$csrf_token" \
    --data-urlencode "title=$page_marker" \
    --data-urlencode "slug=$page_slug" \
    --data-urlencode "data={\"body\":\"$page_marker\"}" \
    "$base_url/administrator/content")"
[[ "$create_status" == 303 ]] || fail "administrator content creation returned HTTP $create_status"
editor_location="$(header_value location "$probe_root/create.headers")"
[[ "$editor_location" =~ ^/administrator/content/([^/]+)/edit$ ]] \
    || fail 'administrator content creation did not redirect to the editor'
content_id="${BASH_REMATCH[1]}"

transition_content() {
    local target="$1"
    local current_body="$probe_root/transition-${target}.editor"
    local status

    status="$(http_status "$current_body" "$probe_root/transition-${target}.get.headers" \
        --header "User-Agent: $user_agent" --header "Cookie: $session_cookie" \
        "$base_url/administrator/content/$content_id/edit")"
    [[ "$status" == 200 ]] || fail "content editor returned HTTP $status before $target transition"
    local csrf
    local version
    csrf="$(csrf_from_html "$current_body")"
    version="$(version_from_html "$current_body")"
    [[ -n "$csrf" && -n "$version" ]] || fail "content editor omitted CSRF or version before $target transition"
    status="$(http_status "$probe_root/transition-${target}.body" "$probe_root/transition-${target}.headers" \
        --request POST \
        --header "User-Agent: $user_agent" --header "Cookie: $session_cookie" \
        --data-urlencode "_csrf=$csrf" \
        --data-urlencode "version=$version" \
        --data-urlencode "status=$target" \
        "$base_url/administrator/content/$content_id/transition")"
    [[ "$status" == 303 ]] || fail "administrator $target transition returned HTTP $status"
}

transition_content review
transition_content published

published_status="$(http_status "$probe_root/published.body" "$probe_root/published.headers" \
    "$base_url/pages/$page_slug")"
[[ "$published_status" == 200 ]] || fail "published page returned HTTP $published_status"
grep --fixed-strings --quiet "$page_marker" "$probe_root/published.body" \
    || fail 'published page did not contain the administrator-authored content'

limited_status="$(http_status "$probe_root/limited.body" "$probe_root/limited.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_READ_TOKEN" \
    --header 'Content-Type: application/json' \
    --header "Idempotency-Key: denied-$unique_suffix" \
    --data "{\"title\":\"Denied\",\"slug\":\"denied-$unique_suffix\",\"data\":{}}" \
    "$base_url/api/v1/content")"
[[ "$limited_status" == 403 ]] || fail "capability-limited API token returned HTTP $limited_status instead of 403"

limited_admin_email="limited-$unique_suffix@kumwe.test"
limited_admin_password="$(openssl rand -base64 32 | tr -d '\n')"
limited_admin_request="$(jq -nc \
    --arg email "$limited_admin_email" \
    --arg password "$limited_admin_password" \
    '{email: $email, display_name: "Limited operator", password: $password, status: "active"}')"
limited_admin_create_status="$(http_status "$probe_root/limited-admin-create.body" \
    "$probe_root/limited-admin-create.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    --header 'Content-Type: application/json' \
    --header "Idempotency-Key: limited-user-$unique_suffix" \
    --data "$limited_admin_request" \
    "$base_url/api/v1/users")"
[[ "$limited_admin_create_status" == 201 ]] \
    || fail "limited administrator fixture creation returned HTTP $limited_admin_create_status"
limited_login_status="$(http_status "$probe_root/limited-login.body" "$probe_root/limited-login.headers" \
    --request POST \
    --header "User-Agent: $user_agent" \
    --data-urlencode "email=$limited_admin_email" \
    --data-urlencode "password=$limited_admin_password" \
    "$base_url/administrator/login")"
unset limited_admin_password limited_admin_request
[[ "$limited_login_status" == 303 ]] || fail "limited user login returned HTTP $limited_login_status"
limited_session_cookie="$(header_value set-cookie "$probe_root/limited-login.headers" | cut -d';' -f1)"
[[ "$limited_session_cookie" == kumwe_administrator=* ]] || fail 'limited user login did not issue a session cookie'
limited_admin_status="$(http_status "$probe_root/limited-admin.body" "$probe_root/limited-admin.headers" \
    --header "User-Agent: $user_agent" --header "Cookie: $limited_session_cookie" \
    "$base_url/administrator")"
[[ "$limited_admin_status" == 403 ]] \
    || fail "user without administrator.access received HTTP $limited_admin_status instead of 403"

api_request="{\"title\":\"API $page_marker\",\"slug\":\"api-$page_slug\",\"data\":{\"body\":\"API $page_marker\"}}"
idempotency_key="create-$unique_suffix"
api_status="$(http_status "$probe_root/api.body" "$probe_root/api.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    --header 'Content-Type: application/json' \
    --header "Idempotency-Key: $idempotency_key" \
    --data "$api_request" \
    "$base_url/api/v1/content")"
[[ "$api_status" == 201 ]] || fail "REST content mutation returned HTTP $api_status"
api_content_id="$(jq -er '.id' "$probe_root/api.body")"
[[ -n "$api_content_id" ]] || fail 'REST content mutation omitted the content ID'

replay_status="$(http_status "$probe_root/replay.body" "$probe_root/replay.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    --header 'Content-Type: application/json' \
    --header "Idempotency-Key: $idempotency_key" \
    --data "$api_request" \
    "$base_url/api/v1/content")"
[[ "$replay_status" == 201 ]] || fail "idempotent REST replay returned HTTP $replay_status"
[[ "$(header_value idempotency-replayed "$probe_root/replay.headers")" == true ]] \
    || fail 'idempotent REST replay did not carry Idempotency-Replayed: true'
cmp --silent "$probe_root/api.body" "$probe_root/replay.body" \
    || fail 'idempotent REST replay body changed'

read_status="$(http_status "$probe_root/read.body" "$probe_root/read.headers" \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    "$base_url/api/v1/content/$api_content_id")"
[[ "$read_status" == 200 ]] || fail "REST content read returned HTTP $read_status"

mcp_initialize='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"kumwe-deployment-probe","version":"2.0.0"}}}'
mcp_status="$(http_status "$probe_root/mcp-initialize.body" "$probe_root/mcp-initialize.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    --header 'Accept: application/json, text/event-stream' \
    --header 'Content-Type: application/json' \
    --data "$mcp_initialize" \
    "$base_url/mcp")"
[[ "$mcp_status" == 200 ]] || fail "MCP initialize returned HTTP $mcp_status"
jq -e '.id == 1 and .result.protocolVersion != null and .error == null' \
    "$probe_root/mcp-initialize.body" >/dev/null \
    || fail 'MCP initialize returned an invalid JSON-RPC result'
mcp_session="$(header_value mcp-session-id "$probe_root/mcp-initialize.headers")"
[[ "$mcp_session" =~ ^[A-Fa-f0-9-]{36}$ ]] || fail 'MCP initialize did not establish a session'

mcp_status="$(http_status "$probe_root/mcp-initialized.body" "$probe_root/mcp-initialized.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    --header 'Accept: application/json, text/event-stream' \
    --header 'Content-Type: application/json' \
    --header 'Mcp-Protocol-Version: 2025-11-25' \
    --header "Mcp-Session-Id: $mcp_session" \
    --data '{"jsonrpc":"2.0","method":"notifications/initialized"}' \
    "$base_url/mcp")"
[[ "$mcp_status" == 202 ]] || fail "MCP initialized notification returned HTTP $mcp_status"

mcp_status="$(http_status "$probe_root/mcp-read.body" "$probe_root/mcp-read.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    --header 'Accept: application/json, text/event-stream' \
    --header 'Content-Type: application/json' \
    --header 'Mcp-Protocol-Version: 2025-11-25' \
    --header "Mcp-Session-Id: $mcp_session" \
    --data '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"kumwe_content_list","arguments":{}}}' \
    "$base_url/mcp")"
[[ "$mcp_status" == 200 ]] || fail "MCP read tool returned HTTP $mcp_status"
jq -e '.id == 2 and .result != null and .error == null' "$probe_root/mcp-read.body" >/dev/null \
    || fail 'MCP read tool returned an invalid JSON-RPC result'

mcp_write='{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"kumwe_content_create","arguments":{"operationId":"acceptance-content-0001","title":"MCP acceptance","slug":"mcp-acceptance","body":"Created through MCP"}}}'
mcp_status="$(http_status "$probe_root/mcp-write.body" "$probe_root/mcp-write.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    --header 'Accept: application/json, text/event-stream' \
    --header 'Content-Type: application/json' \
    --header 'Mcp-Protocol-Version: 2025-11-25' \
    --header "Mcp-Session-Id: $mcp_session" \
    --data "$mcp_write" \
    "$base_url/mcp")"
[[ "$mcp_status" == 200 ]] || fail "MCP write tool returned HTTP $mcp_status"
jq -e '.id == 3 and .result != null and .error == null' "$probe_root/mcp-write.body" >/dev/null \
    || fail 'MCP write tool returned an invalid JSON-RPC result'

mcp_write_replay="${mcp_write/\"id\":3/\"id\":4}"
mcp_status="$(http_status "$probe_root/mcp-replay.body" "$probe_root/mcp-replay.headers" \
    --request POST \
    --header "Authorization: Bearer $KUMWE_PROBE_API_TOKEN" \
    --header 'Accept: application/json, text/event-stream' \
    --header 'Content-Type: application/json' \
    --header 'Mcp-Protocol-Version: 2025-11-25' \
    --header "Mcp-Session-Id: $mcp_session" \
    --data "$mcp_write_replay" \
    "$base_url/mcp")"
[[ "$mcp_status" == 200 ]] || fail "MCP idempotent replay returned HTTP $mcp_status"
jq -e '.id == 4 and .result != null and .error == null' "$probe_root/mcp-replay.body" >/dev/null \
    || fail 'MCP idempotent replay returned an invalid JSON-RPC result'
jq -S '.result' "$probe_root/mcp-write.body" > "$probe_root/mcp-write.result"
jq -S '.result' "$probe_root/mcp-replay.body" > "$probe_root/mcp-replay.result"
cmp --silent "$probe_root/mcp-write.result" "$probe_root/mcp-replay.result" \
    || fail 'MCP idempotent replay result changed'

if [[ -n "${KUMWE_PROBE_STATE_FILE:-}" ]]; then
    [[ "$KUMWE_PROBE_STATE_FILE" = /* ]] || fail 'KUMWE_PROBE_STATE_FILE must be absolute'
    umask 077
    jq -n \
        --arg api_content_id "$api_content_id" \
        --arg content_id "$content_id" \
        --arg page_slug "$page_slug" \
        '{api_content_id: $api_content_id, content_id: $content_id, page_slug: $page_slug}' \
        > "$KUMWE_PROBE_STATE_FILE"
fi

echo "Kumwe deployment probe passed for page $content_id and API content $api_content_id."
