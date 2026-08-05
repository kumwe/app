<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Extension\Application\ExtensionRegistryLease;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Host-only break-glass service. It is wired only to the confirmed recovery CLI command. */
final readonly class DoctrineAdministratorThemeRecovery
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private ExtensionRuntimeMapCompiler $runtime,
        private object $recoveryCapability,
    ) {
    }

    public function recover(object $capability, ExtensionRegistryLease $lease): void
    {
        if ($capability !== $this->recoveryCapability) {
            throw new RuntimeException('Administrator recovery requires the console break-glass capability.');
        }
        $lease->renew();
        $this->transactions->transactional(function () use ($lease): void {
            $lease->renew();
            $fence = $this->database->fetchOne(sprintf(
                'SELECT fence FROM %s WHERE singleton_key = 1',
                $this->tables->quoted('extension_registry_fence'),
            ));
            if (!is_numeric($fence) || (int) $fence !== $lease->fence()) {
                throw new RuntimeException('Administrator recovery lost its database fence.');
            }
            $activation = $this->database->fetchAssociative(sprintf(
                "SELECT extension_id FROM %s WHERE surface = 'administrator'",
                $this->tables->quoted('theme_activations'),
            ));
            if ($activation === false) {
                throw new RuntimeException('The administrator theme activation record is missing.');
            }
            $extensionId = $activation['extension_id'] ?? null;
            $now = $this->clock->now();
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET extension_id = NULL, version = version + 1, activated_by = ?, activated_at = ? '
                . "WHERE surface = 'administrator'",
                $this->tables->quoted('theme_activations'),
            ), ['system:break-glass-theme-recovery', $now], [Types::STRING, Types::DATETIME_IMMUTABLE]);
            if ($affected !== 1) {
                throw new RuntimeException('The administrator theme could not be recovered.');
            }
            if (is_string($extensionId)) {
                $active = $this->database->fetchOne(sprintf(
                    'SELECT (SELECT COUNT(*) FROM %s WHERE extension_id = ?) '
                    . '+ (SELECT COUNT(*) FROM %s WHERE extension_id = ?)',
                    $this->tables->quoted('theme_activations'),
                    $this->tables->quoted('site_theme_activations'),
                ), [$extensionId, $extensionId]);
                if (!is_int($active) && (!is_string($active) || preg_match('/^[0-9]+$/D', $active) !== 1)) {
                    throw new RuntimeException('The active theme assignment count is invalid.');
                }
                if ((int) $active === 0) {
                    $this->database->executeStatement(sprintf(
                        "UPDATE %s SET status = 'disabled', registry_version = registry_version + 1, updated_at = ? "
                        . "WHERE id = ? AND extension_type = 'template'",
                        $this->tables->quoted('extensions'),
                    ), [$now, $extensionId], [Types::DATETIME_IMMUTABLE, Types::GUID]);
                }
            }
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $now,
                'system:break-glass-theme-recovery',
                'theme.administrator.recover',
                'extension',
                'core:administrator',
                'success',
            ));
            if (!is_string($extensionId) || $extensionId === '') {
                throw new RuntimeException('No administrator theme is active for recovery.');
            }
            $identifier = $this->database->fetchOne(sprintf(
                'SELECT identifier FROM %s WHERE id = ?',
                $this->tables->quoted('extensions'),
            ), [$extensionId]);
            if (!is_string($identifier) || $identifier === '') {
                throw new RuntimeException('The recovered administrator theme is not installed.');
            }
            $this->runtime->stageAdministratorRecovery('theme.administrator.recover', $identifier);
            $lease->renew();
        });
    }
}
