#!/usr/bin/env bash
# Real HTTP source/restore acceptance. Credentials stay in caller-owned files and a private temporary directory.
# seed writes a secret-free receipt before backup; verify replays that exact source mutation after restore.
set -Eeuo pipefail
umask 077
fail() { echo "Kumwe HTTP recovery drill failed: $*" >&2; exit 1; }
[[ $# == 3 && ( "$1" == seed || "$1" == verify ) ]] \
    || fail 'usage: restore-http-drill.sh seed|verify REQUEST.json RECEIPT.json'
mode="$1" request="$2" receipt="$3"
origin="${KUMWE_DRILL_HTTP_ORIGIN:?configure the isolated source or restored HTTP origin}"
[[ "$origin" =~ ^https://[a-zA-Z0-9.-]+(:[0-9]+)?$ || "$origin" =~ ^http://(127\.0\.0\.1|localhost)(:[0-9]+)?$ ]] \
    || fail 'HTTP origin must use HTTPS or loopback HTTP, without a path'
for variable in KUMWE_DRILL_LOGIN_EMAIL_FILE KUMWE_DRILL_LOGIN_PASSWORD_FILE KUMWE_DRILL_API_TOKEN_FILE; do
    [[ -s "${!variable:-}" ]] || fail "$variable must name a non-empty credential file"
done
jq -e '
    .format == "kumwe-recovery-http-request-v1"
    and ((.site // "default") | test("^[a-z0-9][a-z0-9._:-]{0,190}$"))
    and (.method == "POST" or .method == "PATCH" or .method == "PUT")
    and (.path | test("^/api/v1/business/records/[a-zA-Z0-9][a-zA-Z0-9._:-]*(/[a-zA-Z0-9][a-zA-Z0-9._:-]*)*$"))
    and (.key | test("^[a-zA-Z0-9_-]{8,128}$"))
    and (.if_match | type == "string" and (test("[\\r\\n]") | not))
    and (.body | type == "object")
    and (.approval_request_id == null or
        (.approval_request_id | test("^[a-fA-F0-9-]{36}$")))
' "$request" >/dev/null || fail 'invalid recovery request fixture'
[[ "$mode" != verify || -s "$receipt" ]] || fail 'source acceptance receipt is missing'
[[ "$mode" != seed || ! -e "$receipt" ]] || fail 'source receipt already exists; use a new fixture'
work="$(mktemp -d)"
trap 'rm -rf -- "$work"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
curl_arguments=(--silent --show-error --connect-timeout 10 --max-time 30 --proto '=http,https' \
    --user-agent 'kumwe-recovery-http/2.0')
http() {
    curl "${curl_arguments[@]}" --output "$work/body" --dump-header "$work/headers" \
        --write-out '%{http_code}' "$@"
}
header() {
    awk -v name="$1" 'tolower($1) == tolower(name ":") {sub(/^[^:]*:[[:space:]]*/, ""); sub(/\r$/, ""); print}' \
        "$work/headers" | tail -n 1
}
# Refuse a public 200 masquerading as an authenticated administrator page.
[[ "$(http "$origin/administrator")" == 303 ]] || fail 'anonymous administrator access was not refused'
[[ "$(header Location)" == /administrator/login ]] || fail 'anonymous administrator redirect differs'
[[ "$(http --cookie-jar "$work/cookies" "$origin/administrator/login")" == 200 ]] \
    || fail 'login form unavailable'
csrf="$(grep -o 'name="_csrf"[^>]*' "$work/body" | sed -n 's/.*value="\([a-zA-Z0-9_-]*\)".*/\1/p')"
[[ -n "$csrf" && "$csrf" != *$'\n'* ]] || fail 'login form must expose one CSRF token'
printf '%s' "$csrf" > "$work/csrf"
[[ "$(http --cookie "$work/cookies" --cookie-jar "$work/cookies" \
    --data-urlencode "email@$KUMWE_DRILL_LOGIN_EMAIL_FILE" \
    --data-urlencode "password@$KUMWE_DRILL_LOGIN_PASSWORD_FILE" \
    --data-urlencode "_csrf@$work/csrf" "$origin/administrator/login")" == 303 ]] || fail 'restored login failed'
[[ "$(header Location)" == /administrator ]] || fail 'login did not establish administrator authentication'
[[ "$(http --cookie "$work/cookies" "$origin/administrator")" == 200 ]] || fail 'authenticated HTTP access failed'
# Tokens are never passed on argv or emitted in evidence. Do not follow redirects with credentials.
token="$(<"$KUMWE_DRILL_API_TOKEN_FILE")"
[[ "$token" != *$'\n'* && "$token" != *$'\r'* ]] || fail 'invalid token file'
printf 'Authorization: Bearer %s\nContent-Type: application/json\n' "$token" > "$work/api-headers"
printf 'Kumwe-Site: %s\n' "$(jq -r '.site // "default"' "$request")" >> "$work/api-headers"
unset token
approval="$(jq -r '.approval_request_id // ""' "$request")"
approval_digest=''
if [[ -n "$approval" ]]; then
    [[ "$(http --header "@$work/api-headers" "$origin/api/v1/business/approvals/$approval")" == 200 ]] \
        || fail 'restored approval is unavailable to its requester'
    jq -e --arg id "$approval" '.approval_request_id == $id and .status == "consumed"
        and .can_approve == false and .can_cancel == false and .can_revoke == false' "$work/body" >/dev/null \
        || fail 'restored approval did not preserve spent state and terminal controls'
    approval_digest="$(jq -cS . "$work/body" | sha256sum | cut -d' ' -f1)"
fi
request_digest="$(jq -cS . "$request" | sha256sum | cut -d' ' -f1)"
if [[ "$mode" == verify ]]; then
    jq -e --arg request "$request_digest" --arg approval "$approval_digest" '
        .format == "kumwe-recovery-http-receipt-v1" and .request_sha256 == $request
        and .approval_sha256 == $approval
    ' "$receipt" >/dev/null || fail 'restored request or approval differs from source acceptance'
fi
printf 'Idempotency-Key: %s\n' "$(jq -r '.key' "$request")" >> "$work/api-headers"
if_match="$(jq -r '.if_match' "$request")"
[[ -z "$if_match" ]] || printf 'If-Match: %s\n' "$if_match" >> "$work/api-headers"
jq -c '.body' "$request" > "$work/mutation"
path="$(jq -r '.path' "$request")"
method="$(jq -r '.method' "$request")"
status="$(http --request "$method" --header "@$work/api-headers" --data-binary "@$work/mutation" "$origin$path")"
[[ "$status" == 200 || "$status" == 201 ]] || fail "idempotent mutation failed with HTTP $status"
response_digest="$(jq -cS 'del(.replayed)' "$work/body" | sha256sum | cut -d' ' -f1)"
etag="$(header ETag)"
[[ -n "$etag" ]] || fail 'mutation omitted its entity version'
if [[ "$mode" == seed ]]; then
    [[ ! -e "$receipt" ]] || fail 'source receipt already exists; use a new fixture'
    [[ "$(header Idempotency-Replayed)" != true ]] || fail 'source mutation must be fresh'
    jq -n --arg request "$request_digest" --arg response "$response_digest" --arg approval "$approval_digest" \
        --arg status "$status" --arg etag "$etag" '{format: "kumwe-recovery-http-receipt-v1",
        request_sha256: $request, response_sha256: $response, approval_sha256: $approval,
        status: $status, etag: $etag}' > "$receipt"
else
    [[ "$(header Idempotency-Replayed)" == true ]] || fail 'restored mutation executed again instead of replaying'
    jq -e --arg response "$response_digest" --arg status "$status" --arg etag "$etag" '
        .response_sha256 == $response and .status == $status and .etag == $etag
    ' "$receipt" >/dev/null || fail 'restored mutation replay differs from source outcome'
fi
# A second real request must replay the identical outcome under the same key and original If-Match.
[[ "$(http --request "$method" --header "@$work/api-headers" --data-binary "@$work/mutation" "$origin$path")" == "$status" ]] \
    || fail 'second mutation status differs'
[[ "$(header Idempotency-Replayed)" == true && "$(header ETag)" == "$etag" ]] || fail 'second mutation did not replay'
[[ "$(jq -cS 'del(.replayed)' "$work/body" | sha256sum | cut -d' ' -f1)" == "$response_digest" ]] \
    || fail 'second mutation body differs'
jq -n --arg mode "$mode" --arg approval "$([[ -n "$approval" ]] && echo verified || echo not_applicable)" \
    '{mode: $mode, authenticated_http: true, idempotent_mutation: true, approval_spent_state: $approval}'
