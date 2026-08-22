#!/usr/bin/env bash

set -Eeuo pipefail

fail() {
    echo "Kumwe production demonstration: $*" >&2
    exit 1
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || fail "required command '$1' is unavailable"
}

write_secret() {
    local path="$1"
    local bytes="$2"
    if [[ ! -f "$path" ]]; then
        install -m 0600 /dev/null "$path"
        openssl rand -base64 "$bytes" | tr -d '\n' > "$path"
    fi
}

repository_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
state_directory="${KUMWE_DEMO_STATE_DIRECTORY:-/tmp/kumwe-production-demo-$(id -u)}"
http_port="${KUMWE_DEMO_HTTP_PORT:-18080}"
command="${1:-up}"

[[ "$state_directory" == /* ]] || fail 'KUMWE_DEMO_STATE_DIRECTORY must be an absolute path'
case "$state_directory" in
    /|/tmp|"$repository_root") fail 'refusing to use a broad state directory' ;;
esac
[[ "$http_port" =~ ^[0-9]{2,5}$ ]] || fail 'KUMWE_DEMO_HTTP_PORT must be a valid port number'

require_command docker
require_command openssl
require_command curl
docker compose version >/dev/null

install -d -m 0700 "$state_directory"
write_secret "$state_directory/app-secret" 48
write_secret "$state_directory/runtime-signing-key" 48
write_secret "$state_directory/database-password" 32
write_secret "$state_directory/redis-password" 32
if [[ ! -f "$state_directory/runtime-previous-keys.json" ]]; then
    install -m 0600 /dev/null "$state_directory/runtime-previous-keys.json"
    printf '{}\n' > "$state_directory/runtime-previous-keys.json"
fi
write_secret "$state_directory/administrator-password" 24

export KUMWE_APP_IMAGE_REF=kumwe-app:administrator-demo
export KUMWE_WEB_IMAGE_REF=kumwe-web:administrator-demo
export KUMWE_APPLICATION_PULL_POLICY=never
export KUMWE_INFRASTRUCTURE_PULL_POLICY=always
export KUMWE_BASE_URL="https://localhost:$http_port"
export KUMWE_TRUSTED_HOSTS=localhost,127.0.0.1
export KUMWE_TRUSTED_PROXIES=172.16.0.0/12,192.168.0.0/16
export KUMWE_HTTP_BIND=127.0.0.1
export KUMWE_HTTP_PORT="$http_port"
export KUMWE_RELEASE=2.0.0-administrator-demo
export KUMWE_DEPLOYMENT_ID=administrator-demo
export KUMWE_REPLICA_ID=administrator-demo-1
# The demonstration deploys everything by default: the documentation site content
# (all six document layouts), the VDM business dataset (six client companies), and
# the provisioned staff and portal sign-ins (KUMWE_DEMO_ACCESS=false opts out; the
# generated credentials land in the state directory, never in the repository).
export KUMWE_SITE_CONTENT_PROFILE=documentation
export KUMWE_BUSINESS_DEMO=true
export KUMWE_DB_DRIVER=mariadb
export KUMWE_DB_PORT=3306
export KUMWE_DB_SERVER_VERSION=mariadb-12.3.2
export KUMWE_DB_NAME=kumwe_demo
export KUMWE_DB_USER=kumwe
export KUMWE_APP_SECRET_FILE="$state_directory/app-secret"
export KUMWE_RUNTIME_SIGNING_KEY_FILE="$state_directory/runtime-signing-key"
export KUMWE_RUNTIME_PREVIOUS_KEYS_FILE="$state_directory/runtime-previous-keys.json"
export KUMWE_DB_PASSWORD_FILE="$state_directory/database-password"
export KUMWE_REDIS_PASSWORD_FILE="$state_directory/redis-password"

compose=(docker compose --project-name kumwe-administrator-demo --file compose.production.yaml)

if [[ "$command" == down ]]; then
    cd "$repository_root"
    "${compose[@]}" --profile automation down --volumes --remove-orphans
    find "$state_directory" -depth -mindepth 1 -delete
    rmdir "$state_directory"
    echo 'Kumwe production demonstration removed.'
    exit 0
fi

[[ "$command" == up ]] || fail 'usage: tools/production-demo.sh [up|down]'
cd "$repository_root"

docker build \
    --file docker/php/Dockerfile \
    --target runtime \
    --tag "$KUMWE_APP_IMAGE_REF" \
    --build-arg KUMWE_RELEASE="$KUMWE_RELEASE" \
    .
docker build \
    --file docker/php/Dockerfile \
    --target web \
    --tag "$KUMWE_WEB_IMAGE_REF" \
    --build-arg KUMWE_RELEASE="$KUMWE_RELEASE" \
    .

"${compose[@]}" config --quiet
"${compose[@]}" up --detach --wait database redis
"${compose[@]}" run --rm migrate

administrator_password="$(<"$state_directory/administrator-password")"
"${compose[@]}" run \
    --rm \
    --no-deps \
    --env KUMWE_DEMO_ADMIN_PASSWORD="$administrator_password" \
    app \
    sh -euc '
        umask 077
        password_file=/tmp/kumwe-demo-administrator-password
        trap '\''rm -f "$password_file"'\'' EXIT
        printf %s "$KUMWE_DEMO_ADMIN_PASSWORD" > "$password_file"
        php bin/kumwe user:create-admin \
            --email=administrator@kumwe.test \
            --name="Demonstration Administrator" \
            --password-file="$password_file"
    '
unset administrator_password

if [[ "${KUMWE_DEMO_ACCESS:-true}" == true && ! -f "$state_directory/demo-access-credentials.json" ]]; then
    administrator_password="$(<"$state_directory/administrator-password")"
    install -m 0600 /dev/null "$state_directory/demo-access-credentials.json"
    "${compose[@]}" run \
        --rm \
        --no-deps \
        --env KUMWE_DEMO_ADMIN_PASSWORD="$administrator_password" \
        app \
        sh -euc '
            umask 077
            password_file=/tmp/kumwe-demo-administrator-password
            credentials_file=/tmp/kumwe-demo-access-credentials.json
            trap '\''rm -f "$password_file" "$credentials_file"'\'' EXIT
            printf %s "$KUMWE_DEMO_ADMIN_PASSWORD" > "$password_file"
            php bin/kumwe demo:provision-access \
                --admin-email=administrator@kumwe.test \
                --admin-password-file="$password_file" \
                --credentials-file="$credentials_file" >&2
            cat "$credentials_file"
        ' > "$state_directory/demo-access-credentials.json"
    unset administrator_password
fi

# The shipped example extensions install by default through the signed pipeline;
# KUMWE_DEMO_EXTENSIONS=false skips them, or name a subset such as "announcements".
if [[ "${KUMWE_DEMO_EXTENSIONS:-true}" != false ]]; then
    example_selection=''
    [[ "${KUMWE_DEMO_EXTENSIONS:-true}" != true ]] && example_selection="--extensions=${KUMWE_DEMO_EXTENSIONS}"
    administrator_password="$(<"$state_directory/administrator-password")"
    "${compose[@]}" run \
        --rm \
        --no-deps \
        --env KUMWE_DEMO_ADMIN_PASSWORD="$administrator_password" \
        app \
        sh -euc '
            umask 077
            password_file=/tmp/kumwe-demo-administrator-password
            trap '\''rm -f "$password_file"'\'' EXIT
            printf %s "$KUMWE_DEMO_ADMIN_PASSWORD" > "$password_file"
            php bin/kumwe demo:install-examples \
                --admin-email=administrator@kumwe.test \
                --admin-password-file="$password_file" '"$example_selection"'
        '
    unset administrator_password
fi

"${compose[@]}" --profile automation up --detach --wait app web worker scheduler
curl --fail --silent --show-error --retry 20 --retry-connrefused \
    "http://127.0.0.1:$http_port/health/ready" >/dev/null

echo
echo "Kumwe is ready at http://localhost:$http_port/administrator"
echo 'Email: administrator@kumwe.test'
echo "Password: $(<"$state_directory/administrator-password")"
if [[ -f "$state_directory/demo-access-credentials.json" ]]; then
    echo "Demonstration staff and portal sign-ins: $state_directory/demo-access-credentials.json"
fi
echo "Stop and remove the isolated demo with: $0 down"
