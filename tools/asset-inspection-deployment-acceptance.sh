#!/usr/bin/env bash

set -Eeuo pipefail

fail() {
    echo "Kumwe asset-inspection deployment acceptance failed: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command '$1' is unavailable"
}

require_value() {
    local variable_name="$1"
    [[ -n "${!variable_name:-}" ]] || fail "required environment variable '$variable_name' is empty"
}

[[ $# -eq 1 ]] || fail 'usage: tools/asset-inspection-deployment-acceptance.sh package|grant|exercise'
mode="$1"
[[ "$mode" == package || "$mode" == grant || "$mode" == exercise ]] \
    || fail 'mode must be package, grant or exercise'

for command in awk cmp curl date docker grep jq openssl sed seq sha256sum tr; do
    require_command "$command"
done
for variable in \
    KUMWE_ACCEPTANCE_ADMIN_PASSWORD_FILE \
    KUMWE_ACCEPTANCE_CLI_TOKEN_FILE \
    KUMWE_ACCEPTANCE_WORK_ROOT; do
    require_value "$variable"
done

[[ "$KUMWE_ACCEPTANCE_WORK_ROOT" = /* && -d "$KUMWE_ACCEPTANCE_WORK_ROOT" ]] \
    || fail 'KUMWE_ACCEPTANCE_WORK_ROOT must be an existing absolute directory'
work_root="$(cd -- "$KUMWE_ACCEPTANCE_WORK_ROOT" && pwd -P)"
repository_root="$(pwd -P)"
case "$work_root" in
    / | /home | /root | /workspace) fail "refusing unsafe acceptance work root '$work_root'" ;;
esac
[[ -r "$KUMWE_ACCEPTANCE_ADMIN_PASSWORD_FILE" ]] || fail 'administrator password file is unreadable'
[[ -r "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" ]] || fail 'management token file is unreadable'
[[ -f "$repository_root/tests/Support/asset-inspection-deployment-acceptance.php" ]] \
    || fail 'the asset-inspection acceptance support entry point is unavailable'

compose_file="${KUMWE_ACCEPTANCE_COMPOSE_FILE:-compose.production.yaml}"
[[ -f "$compose_file" ]] || fail "Compose file '$compose_file' is unavailable"
base_url="${KUMWE_ACCEPTANCE_BASE_URL:-http://127.0.0.1:18080}"
site="${KUMWE_ACCEPTANCE_SITE:-default}"
admin_email="${KUMWE_ACCEPTANCE_ADMIN_EMAIL:-administrator@kumwe.test}"
state_file="$work_root/asset-inspection-state.json"
manifest_file="$work_root/asset-inspection-source-manifest.json"
cli_token_file="$work_root/asset-inspection-cli-token"
api_token_file="$work_root/asset-inspection-api-token"
mcp_token_file="$work_root/asset-inspection-mcp-token"
policy_password_file="$work_root/asset-inspection-policy-password"
projection_evidence_file="$work_root/asset-inspection-projection.json"
projection_id='kumwe.asset-inspection-example.inspection-activity'
acceptance_organization='asset-inspection-acceptance'
bootstrap_capabilities='administrator.access,automation.manage,business.schema.approve,business.schema.execute,business.schema.plan,business.schema.read,content.read,content.create,content.update,content.submit,content.review,content.publish,extensions.manage,users.manage'
management_capabilities='administrator.access,automation.manage,business.record.action,business.record.browse,business.record.create,business.record.export,business.record.read,business.record.relate,business.record.report,business.record.update,business.schema.approve,business.schema.execute,business.schema.plan,business.schema.read,business.security.manage,business.step_up.manage,extensions.manage,users.manage'

compose() {
    docker compose --file "$compose_file" "$@"
}

app() {
    compose exec -T app /usr/local/bin/kumwe-entrypoint "$@"
}

app_token() {
    local credential_file="$1"
    shift
    local credential
    credential="$(<"$credential_file")"
    [[ "$credential" =~ ^[A-Za-z0-9_-]{32,}$ ]] || fail 'an acceptance token has an invalid form'
    compose exec -T \
        --env "KUMWE_ACCEPTANCE_TOKEN=$credential" \
        app /usr/local/bin/kumwe-entrypoint sh -euc '
            umask 077
            token_file="$(mktemp)"
            trap '\''rm -f "$token_file"'\'' EXIT
            printf %s "$KUMWE_ACCEPTANCE_TOKEN" > "$token_file"
            php bin/kumwe "$@" --site="${KUMWE_ACCEPTANCE_SITE:-default}" --token-file="$token_file"
        ' sh "$@"
}

acceptance_php() {
    local password
    password="$(<"$KUMWE_ACCEPTANCE_ADMIN_PASSWORD_FILE")"
    local -a policy_environment=()
    if [[ -n "${KUMWE_ACCEPTANCE_POLICY_EMAIL:-}" && -n "${KUMWE_ACCEPTANCE_POLICY_PASSWORD:-}" ]]; then
        policy_environment=(
            --env KUMWE_ACCEPTANCE_POLICY_EMAIL
            --env KUMWE_ACCEPTANCE_POLICY_PASSWORD
        )
    fi
    compose run --rm --no-deps \
        --volume "$repository_root/tests/Support:/var/www/kumwe/tests/Support:ro" \
        --env "KUMWE_ACCEPTANCE_ADMIN_EMAIL=$admin_email" \
        --env "KUMWE_ACCEPTANCE_ADMIN_PASSWORD=$password" \
        --env "KUMWE_ACCEPTANCE_ORGANIZATION=$acceptance_organization" \
        "${policy_environment[@]}" \
        app sh -euc '
            php bin/kumwe extension:runtime:materialize >/dev/null
            php tests/Support/asset-inspection-deployment-acceptance.php "$@"
        ' sh "$@"
}

replace_management_token() {
    local requested_capabilities="$1"
    local organization="${2:-}"
    local password output token replacement
    password="$(<"$KUMWE_ACCEPTANCE_ADMIN_PASSWORD_FILE")"
    output="$(compose exec -T \
        --env "KUMWE_ACCEPTANCE_ADMIN_PASSWORD=$password" \
        --env "KUMWE_ACCEPTANCE_MANAGEMENT_CAPABILITIES=$requested_capabilities" \
        --env "KUMWE_ACCEPTANCE_ORGANIZATION=$organization" \
        app /usr/local/bin/kumwe-entrypoint sh -euc '
            umask 077
            password_file="$(mktemp)"
            trap '\''rm -f "$password_file"'\'' EXIT
            printf %s "$KUMWE_ACCEPTANCE_ADMIN_PASSWORD" > "$password_file"
            set --
            if [ -n "$KUMWE_ACCEPTANCE_ORGANIZATION" ]; then
                set -- --organization="$KUMWE_ACCEPTANCE_ORGANIZATION"
            fi
            php bin/kumwe token:create \
                --site=default \
                --email=administrator@kumwe.test \
                --name=asset-inspection-management-refresh \
                --capabilities="$KUMWE_ACCEPTANCE_MANAGEMENT_CAPABILITIES" \
                --audience=kumwe-cli \
                --purpose=management \
                --password-file="$password_file" \
                "$@"
        ')"
    token="$(sed -n '2p' <<< "$output")"
    [[ "$token" =~ ^[A-Za-z0-9_-]{32,}$ ]] || fail 'a refreshed management token was not issued'
    replacement="$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE.next.$$"
    [[ ! -e "$replacement" ]] || fail 'the protected management-token replacement path already exists'
    umask 077
    printf %s "$token" > "$replacement"
    chmod 0600 "$replacement"
    mv -- "$replacement" "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE"
}

refresh_bootstrap_token() {
    replace_management_token "$bootstrap_capabilities"
}

refresh_management_token() {
    local extension_capabilities="${1:-}"
    local requested_capabilities="$management_capabilities"
    if [[ -n "$extension_capabilities" ]]; then
        requested_capabilities+=",$extension_capabilities"
    fi
    replace_management_token "$requested_capabilities" "$acceptance_organization"
}

apply_policy_profile() {
    local policy_email='asset-inspection-policy-operator@kumwe.test'
    [[ ! -e "$policy_password_file" ]] || fail "policy password '$policy_password_file' already exists"
    umask 077
    openssl rand -base64 32 | tr -d '\n' > "$policy_password_file"
    chmod 0600 "$policy_password_file"
    export KUMWE_ACCEPTANCE_POLICY_EMAIL="$policy_email"
    export KUMWE_ACCEPTANCE_POLICY_PASSWORD
    KUMWE_ACCEPTANCE_POLICY_PASSWORD="$(<"$policy_password_file")"
    acceptance_php apply-policy \
        | jq -e --arg organization "$acceptance_organization" '
            .organization.identifier == $organization
            and (.profile_policy_ids | length == 4)
            and (.seed_policy_ids | length == 6)
            and (.policy_ids | length == 10)
            and .proofs == {enrollment: 1, recovery: 10, totp: 1}
        ' >/dev/null
    refresh_bootstrap_token
    KUMWE_ACCEPTANCE_POLICY_EMAIL='asset-inspection-seed-policy-operator@kumwe.test'
    acceptance_php apply-seed-policy \
        | jq -e '
            (.seed_policy_ids | length == 8)
            and (.policy_ids | length == 8)
            and .proofs == {enrollment: 1, recovery: 7, totp: 0}
        ' >/dev/null
    unset KUMWE_ACCEPTANCE_POLICY_EMAIL KUMWE_ACCEPTANCE_POLICY_PASSWORD
}

issue_token() {
    local output_file="$1"
    local audience="$2"
    local purpose="$3"
    local name="$4"
    local capabilities="$5"
    [[ ! -e "$output_file" ]] || fail "token output '$output_file' already exists"
    local output token
    output="$(app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" token:create \
        --email="$admin_email" \
        --name="$name" \
        --capabilities="$capabilities" \
        --audience="$audience" \
        --purpose="$purpose")"
    token="$(sed -n '2p' <<< "$output")"
    [[ "$token" =~ ^[A-Za-z0-9_-]{32,}$ ]] || fail "token '$name' was not issued"
    umask 077
    printf %s "$token" > "$output_file"
    chmod 0600 "$output_file"
}

install_schema() {
    local definition="$1"
    local plan id checksum risk execution schema_checksum
    local -a confirmation=()
    plan="$(app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" business-schema plan --definition="$definition")"
    id="$(jq -er '.id' <<< "$plan")"
    checksum="$(jq -er '.checksum | select(test("^[0-9a-f]{64}$"))' <<< "$plan")"
    risk="$(jq -er '.risk' <<< "$plan")"
    case "$risk" in
        online_safe_additive)
            ;;
        backfill_required | behavior_changing)
            confirmation=(--confirmation="$checksum")
            ;;
        rebuild_or_locking | destructive)
            fail "fresh schema installation unexpectedly requires recovery evidence ($risk)"
            ;;
        *)
            fail "schema plan '$id' returned unsupported risk '$risk'"
            ;;
    esac
    app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" business-schema approve \
        --plan="$id" --expected-checksum="$checksum" "${confirmation[@]}" >/dev/null
    execution="$(app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" business-schema execute --plan="$id")"
    jq -e --arg plan_id "$id" '
        .plan_id == $plan_id
        and ((.fence | type) == "number" and .fence >= 1)
        and ((.completed_steps | type) == "number" and .completed_steps >= 1)
        and .skipped_steps == 0
        and (.schema_checksum | type == "string" and test("^[0-9a-f]{64}$"))
        and (.completed_at | type == "string"
            and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\\.[0-9]{6}Z$"))
        and .resumed == false
    ' <<< "$execution" >/dev/null
    schema_checksum="$(jq -er '.schema_checksum' <<< "$execution")"
    app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" business-schema get --plan="$id" \
        | jq -e --arg plan_id "$id" --arg schema_checksum "$schema_checksum" '
            .id == $plan_id
            and .status == "completed"
            and .outcome.plan_id == $plan_id
            and .outcome.schema_checksum == $schema_checksum
        ' >/dev/null
}

create_cli_record() {
    local definition="$1"
    local record="$2"
    local operation="$3"
    local values="$4"
    local token
    token="$(<"$cli_token_file")"
    compose exec -T \
        --env "KUMWE_ACCEPTANCE_TOKEN=$token" \
        --env "KUMWE_ACCEPTANCE_VALUES=$values" \
        app /usr/local/bin/kumwe-entrypoint sh -euc '
            umask 077
            token_file="$(mktemp)"
            values_file="$(mktemp)"
            trap '\''rm -f "$token_file" "$values_file"'\'' EXIT
            printf %s "$KUMWE_ACCEPTANCE_TOKEN" > "$token_file"
            printf %s "$KUMWE_ACCEPTANCE_VALUES" > "$values_file"
            php bin/kumwe business-record create \
                --site="${KUMWE_ACCEPTANCE_SITE:-default}" \
                --token-file="$token_file" \
                --definition="$1" \
                --record="$2" \
                --operation-id="$3" \
                --values-file="$values_file"
        ' sh "$definition" "$record" "$operation" \
        | jq -e --arg record "$record" '
            .ok == true
            and .meta.action == "create"
            and .meta.surface == "cli"
            and .data.record_id == $record
            and .data.version == 1
            and .data.replayed == false
        ' >/dev/null
}

api_request() {
    local method="$1"
    local path="$2"
    local operation="$3"
    local body="$4"
    local expected="$5"
    local output="$6"
    local token
    token="$(<"$api_token_file")"
    local arguments=(
        --silent --show-error
        --request "$method"
        --output "$output"
        --write-out '%{http_code}'
        --header "Authorization: Bearer $token"
        --header "Kumwe-Site: $site"
        --header 'Content-Type: application/json'
        --header "Idempotency-Key: $operation"
        --data "$body"
    )
    if [[ -n "$expected" ]]; then
        arguments+=(--header "If-Match: \"v$expected\"")
    fi
    curl "${arguments[@]}" "$base_url$path"
}

create_api_record() {
    local definition="$1"
    local record="$2"
    local operation="$3"
    local values="$4"
    local label="$5"
    local output="$work_root/$label-create.json"
    local body status
    body="$(jq -nc --arg record "$record" --argjson values "$values" \
        '{record_id: $record, values: $values}')"
    status="$(api_request POST "/api/v1/business/records/$definition" "$operation" "$body" '' "$output")"
    [[ "$status" == 201 ]] || fail "$label REST create returned HTTP $status"
    jq -e --arg record "$record" '.record_id == $record' "$output" >/dev/null
}

relate_api_records() {
    local definition="$1"
    local record="$2"
    local version="$3"
    local relationship="$4"
    local target="$5"
    local position="$6"
    local operation="$7"
    local output="$work_root/$operation.json"
    local body status
    body="$(jq -nc --arg target "$target" --argjson position "$position" \
        '{target_record_id: $target, position: $position}')"
    status="$(api_request POST "/api/v1/business/records/$definition/$record/relations/$relationship" \
        "$operation" "$body" "$version" "$output")"
    [[ "$status" == 200 ]] || fail "$relationship REST relation returned HTTP $status"
    jq -e --arg record "$record" '.record_id == $record' "$output" >/dev/null
}

drain_integrations() {
    local iteration output
    for iteration in $(seq 1 100); do
        output="$(app php bin/kumwe integration:work --once --stream=all --max-items=1000)"
        if grep --fixed-strings --quiet 'processed 0 item batch(es)' <<< "$output"; then
            return 0
        fi
    done
    fail 'integration work did not drain within one hundred bounded passes'
}

assert_projection_evidence() {
    [[ -r "$projection_evidence_file" ]] || fail 'projection rebuild evidence is unavailable'
    local inventory
    inventory="$(app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" integration:manage projections)"
    jq -e --arg projection "$projection_id" --slurpfile expected "$projection_evidence_file" '
        ($expected[0]) as $e
        | [.items[] | select(.projection_id == $projection)]
        | length == 1
          and .[0].active_generation.definition_current
          and .[0].active_generation.generation_id == $e.generation_id
          and .[0].active_generation.last_sequence == $e.last_sequence
          and .[0].active_generation.source_checksum == $e.source_checksum
          and .[0].active_generation.projection_checksum == $e.projection_checksum
    ' <<< "$inventory" >/dev/null
}

header_value() {
    local name="$1"
    local file="$2"
    sed -n "s/^${name}:[[:space:]]*//Ip" "$file" | tr -d '\r' | tail -n 1
}

exercise_mcp_report() {
    local token
    token="$(<"$mcp_token_file")"
    local initialize_headers="$work_root/mcp-initialize.headers"
    local initialize_body="$work_root/mcp-initialize.json"
    local status session
    status="$(curl --silent --show-error --request POST \
        --dump-header "$initialize_headers" --output "$initialize_body" --write-out '%{http_code}' \
        --header "Authorization: Bearer $token" --header "Kumwe-Site: $site" \
        --header 'Host: kumwe.test' --header 'Accept: application/json, text/event-stream' \
        --header 'Content-Type: application/json' \
        --data '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"asset-inspection-acceptance","version":"2.0.0"}}}' \
        "$base_url/mcp")"
    [[ "$status" == 200 ]] || fail "MCP initialize returned HTTP $status"
    jq -e '.id == 1 and .result.protocolVersion != null and .error == null' "$initialize_body" >/dev/null
    session="$(header_value mcp-session-id "$initialize_headers")"
    [[ "$session" =~ ^[A-Fa-f0-9-]{36}$ ]] || fail 'MCP session identity is invalid'

    status="$(curl --silent --show-error --request POST --output /dev/null --write-out '%{http_code}' \
        --header "Authorization: Bearer $token" --header "Kumwe-Site: $site" \
        --header 'Host: kumwe.test' --header 'Accept: application/json, text/event-stream' \
        --header 'Content-Type: application/json' --header 'Mcp-Protocol-Version: 2025-11-25' \
        --header "Mcp-Session-Id: $session" \
        --data '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$base_url/mcp")"
    [[ "$status" == 202 ]] || fail "MCP initialized notification returned HTTP $status"

    local result="$work_root/mcp-report.json"
    status="$(curl --silent --show-error --request POST --output "$result" --write-out '%{http_code}' \
        --header "Authorization: Bearer $token" --header "Kumwe-Site: $site" \
        --header 'Host: kumwe.test' --header 'Accept: application/json, text/event-stream' \
        --header 'Content-Type: application/json' --header 'Mcp-Protocol-Version: 2025-11-25' \
        --header "Mcp-Session-Id: $session" \
        --data '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"kumwe_business_report_execute","arguments":{"report":"kumwe.asset-inspection-example.inspection-summary","parameters":{"minimum_score":0}}}}' \
        "$base_url/mcp")"
    [[ "$status" == 200 ]] || fail "MCP report returned HTTP $status"
    jq -e '.id == 2 and .result != null and .error == null' "$result" >/dev/null
    grep --fixed-strings --quiet 'INSPECT-ACCEPT-001' "$result" \
        || fail 'MCP report omitted the accepted inspection'
}

if [[ "$mode" == package ]]; then
    [[ ! -e "$state_file" ]] || fail "state output '$state_file' already exists"
    container_root=/var/www/kumwe/extensions/.asset-inspection-acceptance
    package_one="$container_root/asset-inspection-one.zip"
    package_two="$container_root/asset-inspection-two.zip"
    signature_file="$container_root/asset-inspection.signature.json"
    secret_key="$container_root/asset-inspection.seed"
    public_key="$container_root/asset-inspection.public"
    source=/var/www/kumwe/examples/extensions/asset-inspection
    app sh -euc '[ ! -e "$1" ]; install -d -m 0700 "$1"' sh "$container_root"
    app php bin/kumwe extension:build "$source" --output="$package_one" >/dev/null
    app php bin/kumwe extension:build "$source" --output="$package_two" >/dev/null
    app cmp -s "$package_one" "$package_two"
    app php bin/kumwe extension:inspect "$package_one" \
        | jq -e '.manifest.schema == 4 and .manifest.contributions.version == 2' >/dev/null
    app php bin/kumwe extension:conformance "$package_one" \
        | jq -e '.conforms == true and (.violations | length == 0)' >/dev/null
    compose run --rm --no-deps \
        --volume "$repository_root/tests/Support:/var/www/kumwe/tests/Support:ro" \
        app php tests/Support/asset-inspection-deployment-acceptance.php \
            generate-keypair "$secret_key" "$public_key" >/dev/null
    signature_document="$(app php bin/kumwe extension:sign "$package_one" \
        --key-id=acceptance.asset-inspection.v1 \
        --secret-key-file="$secret_key" \
        --output="$signature_file")"
    signature="$(jq -er '.signature' <<< "$signature_document")"
    package_sha="$(jq -er '.package_sha256 | select(test("^[0-9a-f]{64}$"))' \
        <<< "$signature_document")"
    trust_expiry="$(date --utc --date='+2 years' '+%Y-%m-%dT%H:%M:%SZ')"
    app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" extension:trust add \
        --key=acceptance.asset-inspection.v1 \
        --public-key-file="$public_key" \
        --vendor=kumwe \
        --extension=asset-inspection-example \
        --expires-at="$trust_expiry" >/dev/null
    app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" extension:install "$package_one" \
        --key-id=acceptance.asset-inspection.v1 --signature="$signature" >/dev/null
    app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" extension:activate \
        kumwe/asset-inspection-example >/dev/null
    app sh -euc '
        rm -- "$1" "$2" "$3" "$4" "$5"
        rmdir -- "$6"
    ' sh "$package_one" "$package_two" "$signature_file" "$secret_key" "$public_key" "$container_root"
    app php bin/kumwe extension:runtime:materialize >/dev/null
    compose --profile automation up --detach --wait --force-recreate app web worker scheduler

    for definition in \
        019bc200-0000-7000-8000-000000000001 \
        019bc200-0000-7000-8000-000000000002 \
        019bc200-0000-7000-8000-000000000003 \
        019bc200-0000-7000-8000-000000000004 \
        019bc200-0000-7000-8000-000000000005; do
        install_schema "$definition"
    done
    umask 077
    jq -n --arg package_sha256 "$package_sha" \
        '{format: "kumwe-asset-inspection-package-v1", package_sha256: $package_sha256}' > "$state_file"
    chmod 0600 "$state_file"
    echo "Installed signed asset-inspection package $package_sha on schema 4/SPI 2."
    exit 0
fi

jq -e '.format == "kumwe-asset-inspection-package-v1" and (.package_sha256 | test("^[0-9a-f]{64}$"))' \
    "$state_file" >/dev/null || fail 'the package-phase state is unavailable'

if [[ "$mode" == grant ]]; then
    roles="$(app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" access roles)"
    administrator_role="$(jq -er '[.items[] | select(.code == "administrator") | .id]
        | select(length == 1) | .[0]' <<< "$roles")"
    delegated_capabilities=''
    for capability in \
        kumwe.asset-inspection-example.manage \
        kumwe.asset-inspection-example.view; do
        app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" access grant \
            --role="$administrator_role" \
            --capability="$capability" \
            --scope-type=global >/dev/null
        delegated_capabilities="${delegated_capabilities:+$delegated_capabilities,}$capability"
        refresh_bootstrap_token
    done
    apply_policy_profile
    refresh_management_token "$delegated_capabilities"
    roles="$(app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" access roles)"
    jq -e --arg role "$administrator_role" '
        [.items[] | select(.id == $role) | .grants[].capability]
        | contains([
            "kumwe.asset-inspection-example.manage",
            "kumwe.asset-inspection-example.view"
        ])
    ' <<< "$roles" >/dev/null
    echo "Delegated both active human-delegatable asset-inspection capabilities to the administrator role."
    exit 0
fi

common_record_capabilities='business.record.browse,business.record.read,business.record.create,business.record.update,business.record.action,business.record.relate,business.record.report,business.record.export,automation.manage,kumwe.asset-inspection-example.manage,kumwe.asset-inspection-example.view'
issue_token "$cli_token_file" kumwe-cli management asset-inspection-cli "$common_record_capabilities"
issue_token "$api_token_file" kumwe-http api asset-inspection-api "$common_record_capabilities"
issue_token "$mcp_token_file" kumwe-mcp mcp asset-inspection-mcp \
    'business.record.read,business.record.report,business.record.export,kumwe.asset-inspection-example.view'

location_definition='kumwe.asset-inspection-example.location'
asset_definition='kumwe.asset-inspection-example.asset'
inspection_definition='kumwe.asset-inspection-example.inspection'
finding_definition='kumwe.asset-inspection-example.finding'
measurement_definition='kumwe.asset-inspection-example.measurement'
location='019bc210-0000-7000-8000-000000000001'
asset='019bc210-0000-7000-8000-000000000002'
inspection='019bc210-0000-7000-8000-000000000003'
finding_one='019bc210-0000-7000-8000-000000000004'
finding_two='019bc210-0000-7000-8000-000000000005'
measurement_one='019bc210-0000-7000-8000-000000000006'
measurement_two='019bc210-0000-7000-8000-000000000007'
inspection_denied='019bc210-0000-7000-8000-000000000008'

create_cli_record "$location_definition" "$location" asset-location-create-0001 \
    "$(jq -nc --arg id "$location" '{id: $id, name: "Acceptance Location", zone: "North"}')"
create_api_record "$asset_definition" "$asset" asset-record-create-0001 \
    "$(jq -nc --arg id "$asset" '{id: $id, asset_tag: "ACCEPT-001", name: "Acceptance Asset", active: true}')" asset
asset_replay="$work_root/asset-create-replay.json"
asset_body="$(jq -nc --arg record "$asset" --arg id "$asset" \
    '{record_id: $record, values: {id: $id, asset_tag: "ACCEPT-001", name: "Acceptance Asset", active: true}}')"
[[ "$(api_request POST "/api/v1/business/records/$asset_definition" asset-record-create-0001 \
    "$asset_body" '' "$asset_replay")" == 201 ]] || fail 'asset REST idempotent replay failed'
cmp --silent "$work_root/asset-create.json" "$asset_replay" \
    || fail 'asset REST idempotent replay changed its result'
create_cli_record "$inspection_definition" "$inspection" asset-inspection-create-0001 \
    "$(jq -nc --arg id "$inspection" '{id: $id, reference: "INSPECT-ACCEPT-001", inspection_date: "2026-08-10", raw_score: 82, adjustment: -3, internal_note: "restricted acceptance note"}')"
create_api_record "$finding_definition" "$finding_one" asset-finding-one-create-0001 \
    "$(jq -nc --arg id "$finding_one" '{id: $id, summary: "Guard requires replacement", severity: "high", remediation: "Replace the guard"}')" finding-one
create_cli_record "$finding_definition" "$finding_two" asset-finding-two-create-0001 \
    "$(jq -nc --arg id "$finding_two" '{id: $id, summary: "Label is worn", severity: "low", remediation: "Replace the label"}')"
create_api_record "$measurement_definition" "$measurement_one" asset-measurement-one-create-0001 \
    "$(jq -nc --arg id "$measurement_one" '{id: $id, metric: "temperature", value: "12.3456", unit: "C", acceptable: true}')" measurement-one
create_cli_record "$measurement_definition" "$measurement_two" asset-measurement-two-create-0001 \
    "$(jq -nc --arg id "$measurement_two" '{id: $id, metric: "vibration", value: "9.8765", unit: "mm/s", acceptable: false}')"
create_cli_record "$inspection_definition" "$inspection_denied" asset-inspection-denied-create-0001 \
    "$(jq -nc --arg id "$inspection_denied" '{id: $id, reference: "INSPECT-DENIED-001", inspection_date: "2026-08-10", raw_score: 69, adjustment: 0}')"

app_token "$cli_token_file" business-record relate --definition="$location_definition" --record="$location" \
    --expected-version=1 --relationship=assets --target-record="$asset" --position=0 \
    --operation-id=asset-location-relate-0001 >/dev/null
relate_api_records "$asset_definition" "$asset" 1 inspections "$inspection" 0 asset-inspection-relate-0001
app_token "$cli_token_file" business-record relate --definition="$inspection_definition" --record="$inspection" \
    --expected-version=1 --relationship=findings --target-record="$finding_one" --position=0 \
    --operation-id=asset-finding-one-relate-0001 >/dev/null
relate_api_records "$inspection_definition" "$inspection" 2 findings "$finding_two" 1 asset-finding-two-relate-0001
relate_api_records "$inspection_definition" "$inspection" 3 measurements "$measurement_one" 0 asset-measurement-one-relate-0001
app_token "$cli_token_file" business-record relate --definition="$inspection_definition" --record="$inspection" \
    --expected-version=4 --relationship=measurements --target-record="$measurement_two" --position=1 \
    --operation-id=asset-measurement-two-relate-0001 >/dev/null

drain_integrations
acceptance_php replay >/dev/null
[[ ! -e "$projection_evidence_file" ]] || fail 'projection rebuild evidence already exists'
projection_rebuild="$(app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" integration:manage projection-rebuild \
    --projection="$projection_id")"
jq -e --arg projection "$projection_id" '
    .projection_id == $projection
    and (.generation_id | test("^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"; "i"))
    and .last_sequence >= 1
    and .event_count >= 1
    and .last_sequence >= .event_count
    and (.source_checksum | test("^[0-9a-f]{64}$"))
    and (.projection_checksum | test("^[0-9a-f]{64}$"))
' <<< "$projection_rebuild" >/dev/null
umask 077
jq --sort-keys . <<< "$projection_rebuild" > "$projection_evidence_file"
chmod 0600 "$projection_evidence_file"
assert_projection_evidence
compose --profile automation stop worker scheduler
acceptance_schedule_document="$(app_token "$cli_token_file" automation create \
    --name=asset-inspection-acceptance \
    --cron='* * * * *' \
    --timezone=UTC \
    --job=kumwe.asset-inspection-example.review-overdue \
    --payload='{"site_identifier":"default","minimum_age_days":7}' \
    --queue=kumwe.asset-inspection-example.integration \
    --first-run=2000-01-01T00:00:00Z)"
acceptance_schedule="$(jq -er '.id' <<< "$acceptance_schedule_document")"
app php bin/kumwe schedule:run >/dev/null
app php bin/kumwe queue:work --queue=kumwe.asset-inspection-example.integration --once \
    | grep --fixed-strings --quiet 'drained after 1 job(s)'
queue_policy="$(app_token "$cli_token_file" automation queues)"
jq -e '
    [.items[] | select(.queue == "kumwe.asset-inspection-example.integration")]
    | length == 1
    and .[0].lease_seconds == 60
    and .[0].maximum_attempts == 3
    and .[0].maximum_in_flight == 8
    and .[0].retention_days == 30
    and .[0].runtime_generation >= 0
    and .[0].in_flight == 0
' <<< "$queue_policy" >/dev/null
if app php bin/kumwe queue:work --queue=kumwe.asset-inspection-example.integration \
    --lease-seconds=61 --once >/dev/null 2>&1; then
    fail 'the worker accepted a lease wider than the signed contributed queue policy'
fi
app_token "$cli_token_file" automation purge-queue \
    --queue=kumwe.asset-inspection-example.integration --limit=100 \
    | jq -e '.purged == 0' >/dev/null
acceptance_schedule_version="$(app_token "$cli_token_file" automation schedule \
    --id="$acceptance_schedule" | jq -er '.version')"
app_token "$cli_token_file" automation delete \
    --id="$acceptance_schedule" --version="$acceptance_schedule_version" >/dev/null

parameters=/tmp/asset-inspection-report-parameters.json
app sh -euc 'umask 077; printf '\''{"minimum_score":0}'\'' > "$1"' sh "$parameters"
report="$(app_token "$cli_token_file" business-report run \
    --report=kumwe.asset-inspection-example.inspection-summary --parameters-file="$parameters")"
jq -e '.row_count == 1 and .rows[0].reference == "INSPECT-ACCEPT-001" and .rows[0].risk_score == 79' \
    <<< "$report" >/dev/null
export_request="$(app_token "$cli_token_file" business-report export \
    --report=kumwe.asset-inspection-example.inspection-summary --parameters-file="$parameters")"
artifact="$(jq -er '.id' <<< "$export_request")"
app php bin/kumwe queue:work --queue=exports --once \
    | grep --fixed-strings --quiet 'drained after 1 job(s)'
export_status="$(app_token "$cli_token_file" business-report status --artifact="$artifact")"
export_checksum="$(jq -er '.checksum | select(test("^[0-9a-f]{64}$"))' <<< "$export_status")"
jq -e '.status == "completed" and .row_count == 1' <<< "$export_status" >/dev/null
download=/tmp/asset-inspection-report.csv
app_token "$cli_token_file" business-report download --artifact="$artifact" --output-file="$download" >/dev/null
[[ "$(app sha256sum "$download" | awk '{print $1}')" == "$export_checksum" ]] \
    || fail 'downloaded report export checksum differs from its metadata'

exercise_mcp_report
admin_headers="$work_root/administrator-login.headers"
admin_body="$work_root/administrator-login.body"
admin_password="$(<"$KUMWE_ACCEPTANCE_ADMIN_PASSWORD_FILE")"
[[ "$(curl --silent --show-error --request POST --dump-header "$admin_headers" --output "$admin_body" \
    --write-out '%{http_code}' --data-urlencode "email=$admin_email" \
    --data-urlencode "password=$admin_password" "$base_url/administrator/login")" == 303 ]] \
    || fail 'administrator login failed before contributed-page proof'
admin_cookie="$(header_value set-cookie "$admin_headers" | cut -d';' -f1)"
[[ "$admin_cookie" == kumwe_administrator=* ]] || fail 'administrator login omitted its session cookie'
admin_page="$work_root/asset-inspection-administrator.html"
[[ "$(curl --silent --show-error --output "$admin_page" --write-out '%{http_code}' \
    --header "Cookie: $admin_cookie" \
    "$base_url/administrator/extensions/kumwe/asset-inspection-example")" == 200 ]] \
    || fail 'contributed administrator page was unavailable'
grep --fixed-strings --quiet 'not an ERP module' "$admin_page" \
    || fail 'contributed administrator page omitted its neutral-example notice'

compose --profile automation up --detach --wait --force-recreate app web worker scheduler
curl --fail --silent --show-error --retry 20 --retry-connrefused "$base_url/health/ready" >/dev/null
assert_projection_evidence
app sh -euc 'umask 077; printf '\''{"minimum_score":0}'\'' > "$1"' sh "$parameters"
post_restart_report="$(app_token "$cli_token_file" business-report run \
    --report=kumwe.asset-inspection-example.inspection-summary --parameters-file="$parameters")"
jq -e '.row_count == 1 and .rows[0].reference == "INSPECT-ACCEPT-001"' <<< "$post_restart_report" >/dev/null

app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" extension:disable kumwe/asset-inspection-example >/dev/null
refresh_management_token
app php bin/kumwe extension:runtime:materialize >/dev/null
compose --profile automation up --detach --wait --force-recreate app web worker scheduler
app php bin/kumwe schedule:run >/dev/null
acceptance_php lifecycle disabled >/dev/null
app_token "$KUMWE_ACCEPTANCE_CLI_TOKEN_FILE" extension:activate kumwe/asset-inspection-example >/dev/null
app php bin/kumwe extension:runtime:materialize >/dev/null
compose --profile automation up --detach --wait --force-recreate app web worker scheduler
app php bin/kumwe schedule:run >/dev/null
refresh_management_token 'kumwe.asset-inspection-example.manage,kumwe.asset-inspection-example.view'
assert_projection_evidence

package_sha="$(jq -er '.package_sha256' "$state_file")"
umask 077
jq -n \
    --arg package_sha256 "$package_sha" \
    --arg export_artifact_id "$artifact" \
    --arg projection_generation_id "$(jq -er '.generation_id' "$projection_evidence_file")" \
    --arg projection_source_checksum "$(jq -er '.source_checksum' "$projection_evidence_file")" \
    --arg projection_checksum "$(jq -er '.projection_checksum' "$projection_evidence_file")" \
    --argjson projection_last_sequence "$(jq -er '.last_sequence' "$projection_evidence_file")" \
    '{
        format: "kumwe-asset-inspection-state-v1",
        package_sha256: $package_sha256,
        export_artifact_id: $export_artifact_id,
        projection_generation_id: $projection_generation_id,
        projection_source_checksum: $projection_source_checksum,
        projection_checksum: $projection_checksum,
        projection_last_sequence: $projection_last_sequence
    }' \
    > "$state_file.next"
mv -- "$state_file.next" "$state_file"
chmod 0600 "$state_file"
state_json="$(<"$state_file")"
admin_password="$(<"$KUMWE_ACCEPTANCE_ADMIN_PASSWORD_FILE")"
compose run --rm --no-deps \
    --volume "$repository_root/tests/Support:/var/www/kumwe/tests/Support:ro" \
    --env "KUMWE_ACCEPTANCE_ADMIN_EMAIL=$admin_email" \
    --env "KUMWE_ACCEPTANCE_ADMIN_PASSWORD=$admin_password" \
    --env "KUMWE_ACCEPTANCE_STATE_JSON=$state_json" \
    app sh -euc '
        umask 077
        state_file="$(mktemp)"
        trap '\''rm -f "$state_file"'\'' EXIT
        printf %s "$KUMWE_ACCEPTANCE_STATE_JSON" > "$state_file"
        php bin/kumwe extension:runtime:materialize >/dev/null
        php tests/Support/asset-inspection-deployment-acceptance.php snapshot "$state_file"
    ' > "$manifest_file"
jq -e '.format == "kumwe-asset-inspection-deployment-acceptance-v1"' "$manifest_file" >/dev/null
chmod 0600 "$manifest_file"

echo "Exercised asset-inspection records, events, projection, job, report/export, MCP, UI, restart and lifecycle."
