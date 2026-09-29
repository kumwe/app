#!/usr/bin/env bash
# Execute preserved drills against an installed no-dev artifact, with only a separate test framework.
set -euo pipefail
artifact_root="${1:?Installed artifact root required}"
qa_vendor="${2:?Separate qualification vendor directory required}"
evidence_root="${3:?Evidence output directory required}"
cd "$artifact_root"
test -f vendor/autoload.php
test -f "$qa_vendor/phpunit/phpunit/phpunit"
install -d "$evidence_root/php" storage/cache storage/logs storage/media storage/operations \
    storage/sessions storage/tmp storage/private/report-exports extensions public/assets/extensions
# Child worker processes inherit this file too, keeping their App code bound to the same artifact.
printf 'auto_prepend_file=%s/tests/Support/release-qualification-bootstrap.php\n' "$artifact_root" \
    > "$evidence_root/php/qualification.ini"
export PHP_INI_SCAN_DIR="${PHP_INI_SCAN_DIR:-/usr/local/etc/php/conf.d}:$evidence_root/php"
cat > "$evidence_root/phpunit.xml" <<EOF_CONFIG
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="$artifact_root/tests/Support/release-qualification-bootstrap.php"
 failOnDeprecation="true" failOnNotice="true" failOnRisky="true" failOnWarning="true"
 failOnSkipped="true" requireCoverageMetadata="true" recordTestRunHistory="false" failOnPhpunitDeprecation="true"/>
EOF_CONFIG
# The immutable parent ledger is installed before the candidate migration command on an empty database.
php tests/Support/install-parent-schema.php
php bin/kumwe database:migrate
php bin/kumwe database:migrate
# Exercise the candidate's real migration runner on its server engine; no conditional skip is accepted.
if [[ "$DB_DRIVER" == pgsql ]]; then
    recovery_filter='testPostgreSqlRollsBackMigrationDdlAndLedgerTogetherBeforeRetry'
else
    recovery_filter='::test(?!PostgreSql|PushedJobRecovery)'
fi
timeout --signal=TERM --kill-after=15 300 php "$qa_vendor/phpunit/phpunit/phpunit" \
    --configuration "$evidence_root/phpunit.xml" --colors=never \
    --log-junit "$evidence_root/schema-recovery.junit.xml" --filter "$recovery_filter" \
    tests/Integration/Persistence/CrashResumableMigrationIntegrationTest.php
timeout --signal=TERM --kill-after=15 180 php "$qa_vendor/phpunit/phpunit/phpunit" \
    --configuration "$evidence_root/phpunit.xml" --colors=never \
    --log-junit "$evidence_root/migration-replay.junit.xml" \
    --filter 'testDatabaseMigrationIsIdempotentAndReady|testMigrationLockSurvivesDdlAndRejectsASecondDatabaseSession' \
    tests/Integration/Persistence/MigrationIntegrationTest.php
timeout --signal=TERM --kill-after=15 300 php tests/Support/release-backlog.php seed "$evidence_root"
while IFS=$'\t' read -r id seconds file; do
    timeout --signal=TERM --kill-after=15 "$seconds" php "$qa_vendor/phpunit/phpunit/phpunit" \
        --configuration "$evidence_root/phpunit.xml" --colors=never \
        --log-junit "$evidence_root/$id.junit.xml" "$file"
done < <(php -r '
    $catalogue = json_decode(file_get_contents("resources/release/qualification-drills.json"), true, 64, JSON_THROW_ON_ERROR);
    foreach ($catalogue["drills"] as $drill) {
        printf("%s\t%d\t%s\n", $drill["id"], $drill["maximum_seconds"], $drill["test"]);
    }
')

timeout --signal=TERM --kill-after=15 260 php tests/Support/release-backlog.php drain "$evidence_root"
