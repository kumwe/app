<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\Access\OwnershipScopeLevel;
use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Kumwe\App\Extension\Contribution\ContributionDefinitionChecksum;
use Kumwe\App\Extension\Contribution\CoreExtensionContributions;
use Kumwe\App\Extension\Runtime\RuntimeCanonicalJson;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Contribution\ContributionOwner;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Seed operator diagnostics authority and refresh existing administrators' authorization epochs.
 *
 * @since  2.0.0
 */
final readonly class OperatorDiagnosticsCapabilityMigration implements RepeatableMigration
{
    /**
     * Append-only capability migration identity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20260929010000_operator_diagnostics_capability';

    /**
     * Bind the installation's authority-catalog tables.
     *
     * @param  TableNames  $tables  Prefix-aware physical names.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the stable schema-ledger identity.
     *
     * @return  string  Immutable migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind migration history to the exact source bytes.
     *
     * @return  string  SHA-256 ledger checksum.
     *
     * @throws  RuntimeException  When the source digest cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The operator diagnostics migration checksum is unavailable.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Reconcile capability, global grants, ownership and epoch invalidation atomically and idempotently.
     *
     * @param   Connection  $database  Installation authority catalog.
     *
     * @return  void
     *
     * @throws  \Doctrine\DBAL\Exception  When catalog persistence fails.
     * @throws  RuntimeException  When the core definition or a stored administrator identity is invalid.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        $database->transactional(function (Connection $database): void {
            $definition = null;
            foreach (CoreExtensionContributions::capabilityDefinitions() as $candidate) {
                if ($candidate->id === OperatorDiagnostics::CAPABILITY) {
                    $definition = $candidate;
                    break;
                }
            }
            if ($definition === null) {
                throw new RuntimeException('The core operator diagnostics capability is missing.');
            }
            $owner = ContributionOwner::core();
            $values = [
                'description' => $definition->description,
                'owner_kind' => 'core',
                'owner_identifier' => $owner->identifier(),
                'allowed_scopes' => RuntimeCanonicalJson::encode($definition->allowedScopes),
                'delegable' => $definition->delegatable,
                'high_impact' => $definition->highImpact,
                'definition_version' => $definition->version,
                'definition_checksum' => ContributionDefinitionChecksum::calculate($owner, $definition),
                'lifecycle_state' => $definition->lifecycle->value,
            ];
            $exists = $database->fetchOne(sprintf(
                'SELECT code FROM %s WHERE code = ?',
                $this->tables->quoted('capabilities'),
            ), [$definition->id]);
            $types = ['delegable' => Types::BOOLEAN, 'high_impact' => Types::BOOLEAN];
            if ($exists === false) {
                $database->insert($this->tables->raw('capabilities'), ['code' => $definition->id, ...$values], $types);
            } else {
                $database->update($this->tables->raw('capabilities'), $values, ['code' => $definition->id], $types);
            }
            $this->ensureOwnership($database, 'capability', $definition->id);

            $changedRoles = [];
            $roles = $database->fetchFirstColumn(sprintf(
                'SELECT id FROM %s WHERE code = ? ORDER BY id',
                $this->tables->quoted('roles'),
            ), ['administrator']);
            foreach ($roles as $roleId) {
                if (!is_string($roleId) || $roleId === '') {
                    throw new RuntimeException('A stored administrator role identity is invalid.');
                }
                $grantId = $database->fetchOne(sprintf(
                    'SELECT id FROM %s WHERE role_id = ? AND capability_code = ? '
                        . "AND scope_type = 'global' AND scope_identifier IS NULL",
                    $this->tables->quoted('role_capability_grants'),
                ), [$roleId, $definition->id]);
                if ($grantId === false) {
                    $grantId = Uuid::uuid5(
                        Uuid::NAMESPACE_URL,
                        'kumwe:administrator:' . $roleId . ':' . $definition->id,
                    )->toString();
                    $database->insert($this->tables->raw('role_capability_grants'), [
                        'id' => $grantId,
                        'role_id' => $roleId,
                        'capability_code' => $definition->id,
                        'scope_type' => 'global',
                        'scope_identifier' => null,
                        'granted_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
                        'granted_by' => null,
                    ], ['granted_at' => Types::DATETIME_IMMUTABLE]);
                    $changedRoles[] = $roleId;
                } elseif (!is_string($grantId) || $grantId === '') {
                    throw new RuntimeException('A stored operator diagnostics grant identity is invalid.');
                }
                $this->ensureOwnership($database, 'grant', $grantId);
            }
            if ($changedRoles !== []) {
                $database->executeStatement(sprintf(
                    'UPDATE %s SET security_epoch = security_epoch + 1 WHERE id IN ('
                        . 'SELECT DISTINCT user_id FROM %s WHERE role_id IN (%s))',
                    $this->tables->quoted('users'),
                    $this->tables->quoted('user_roles'),
                    implode(', ', array_fill(0, count($changedRoles), '?')),
                ), $changedRoles);
            }
        });
    }

    /**
     * Preserve the established default-site ownership convention for core authority-catalog rows.
     *
     * @param   Connection  $database  Installation database.
     * @param   string      $type      Capability or grant resource type.
     * @param   string      $id        Stable catalog identity.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function ensureOwnership(Connection $database, string $type, string $id): void
    {
        $exists = $database->fetchOne(sprintf(
            'SELECT resource_id FROM %s WHERE resource_type = ? AND resource_id = ?',
            $this->tables->quoted('resource_site_ownership'),
        ), [$type, $id]);
        if ($exists === false) {
            $database->insert($this->tables->raw('resource_site_ownership'), [
                'resource_type' => $type,
                'resource_id' => $id,
                'site_identifier' => SiteContext::DEFAULT,
                'scope_level' => OwnershipScopeLevel::Site->value,
                'group_identifier' => null,
            ]);
        }
    }
}
