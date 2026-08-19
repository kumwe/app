<?php

declare(strict_types=1);

use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\DoctrineConnectionFactory;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreSchemaMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\DoctrineMigrationRepository;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Identity\Infrastructure\Security\NativePasswordHasher;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const EXPECTED_CORE_CHECKSUM = '40bf9c3fa708f153453cfbd6caf93c9cef806052eabb6a1bb8ad7a4b71e7dddf';

$configuration = (new ConfigurationFactory())->create(Environment::fromGlobals());
$database = (new DoctrineConnectionFactory($configuration->database))->create();
$tables = new TableNames($database, $configuration->database->tablePrefix);
$core = new CoreSchemaMigration($tables);

if (!hash_equals(EXPECTED_CORE_CHECKSUM, $core->checksum())) {
    throw new RuntimeException('The immutable core migration checksum changed.');
}

$core->up($database);
$now = new DateTimeImmutable('2026-08-04T00:00:00+00:00');
$userId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb901';
$roleId = '018f22e2-7c8b-7ab0-8f3a-88e8026bb902';
$database->insert($tables->raw('users'), [
    'id' => $userId,
    'email' => 'integration-administrator@example.test',
    'email_normalized' => 'integration-administrator@example.test',
    'display_name' => 'Legacy Integration Administrator',
    'status' => 'active',
    'version' => 1,
    'created_at' => $now,
    'updated_at' => $now,
], ['created_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
$database->insert($tables->raw('password_credentials'), [
    'user_id' => $userId,
    'password_hash' => (new NativePasswordHasher())->hash('integration administrator password'),
    'changed_at' => $now,
], ['changed_at' => Types::DATETIME_IMMUTABLE]);
$database->insert($tables->raw('roles'), [
    'id' => $roleId,
    'code' => 'administrator',
    'name' => 'Administrator',
    'created_at' => $now,
], ['created_at' => Types::DATETIME_IMMUTABLE]);
$database->insert($tables->raw('user_roles'), [
    'user_id' => $userId,
    'role_id' => $roleId,
    'assigned_at' => $now,
    'assigned_by' => $userId,
], ['assigned_at' => Types::DATETIME_IMMUTABLE]);
$capabilities = $database->fetchFirstColumn(sprintf(
    'SELECT code FROM %s ORDER BY code',
    $tables->quoted('capabilities'),
));
foreach ($capabilities as $position => $capability) {
    if (!is_string($capability)) {
        throw new RuntimeException('A parent capability is invalid.');
    }
    $database->insert($tables->raw('role_capability_grants'), [
        'id' => sprintf('018f22e2-7c8b-7ab0-8f3a-%012d', 903 + $position),
        'role_id' => $roleId,
        'capability_code' => $capability,
        'scope_type' => 'global',
        'scope_identifier' => null,
        'granted_at' => $now,
        'granted_by' => $userId,
    ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
}
$repository = new DoctrineMigrationRepository($database, $tables);
$repository->ensureLedger();
$repository->record($core->id(), $core->checksum(), 0);

fwrite(STDOUT, "Installed the immutable parent schema.\n");
