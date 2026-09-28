<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\BusinessReporting\Application\ExportSiteByteBudget;
use Kumwe\App\BusinessReporting\Application\ExportSiteByteBudgetExhausted;
use Kumwe\App\Infrastructure\Persistence\TableNames;

/**
 * Charges completed export bytes to one row per site that holds the current UTC day's cumulative total.
 *
 * The charge is one conditional `UPDATE`: it adds the bytes when the row's window is today, restarts the
 * window when it is not, and matches no row when the result would pass the budget. The byte counts are
 * bound as integers so a MySQL-family engine compares the sum numerically rather than as text. Before it,
 * an idempotent upsert makes sure the site's row exists (`ON DUPLICATE KEY UPDATE` on MariaDB and MySQL,
 * `ON CONFLICT DO NOTHING` elsewhere), so both statements run directly in the caller's completion
 * transaction with no savepoint. That order matters: a first charge that updated a missing row and then
 * inserted it would take a gap lock before its insert, and two racing first charges would deadlock, which
 * InnoDB answers by rolling back the whole caller transaction. The upsert waits on a concurrent creator's
 * row lock instead, and the row lock the update takes is held until the completion transaction ends, so
 * concurrent completions for one site serialize and each sees the other's committed total, while other
 * sites never wait.
 * The window is a fixed UTC day, so a site can publish up to twice its budget across one midnight.
 *
 * @since  2.0.0
 */
final readonly class DoctrineExportSiteByteBudget implements ExportSiteByteBudget
{
    /**
     * Default cumulative bytes per site per UTC day (4 GiB, thirty-two artifacts at the 128 MiB ceiling).
     *
     * @var    int
     * @since  2.0.0
     */
    public const int DEFAULT_BYTES_PER_DAY = 4_294_967_296;

    /**
     * Bind the budget to its table and daily allowance.
     *
     * @param   Connection  $database     Connection the completion transaction runs on.
     * @param   TableNames  $tables       Prefixed table names.
     * @param   int         $bytesPerDay  Cumulative bytes one site may publish per UTC day, at least one.
     *
     * @throws  InvalidArgumentException  When the allowance is below one byte.
     *
     * @since   2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private int $bytesPerDay = self::DEFAULT_BYTES_PER_DAY,
    ) {
        if ($bytesPerDay < 1) {
            throw new InvalidArgumentException('A site export byte budget must allow at least one byte a day.');
        }
    }

    /**
     * Charge the bytes to the site's current UTC day, or refuse them.
     *
     * @param   string             $siteIdentifier  Site the artifact belongs to.
     * @param   int                $bytes           Artifact size in bytes.
     * @param   DateTimeImmutable  $at              Completion instant.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the byte count is negative.
     * @throws  ExportSiteByteBudgetExhausted  When the charge would pass the day's budget.
     * @throws  \Doctrine\DBAL\Exception  When either statement fails.
     *
     * @since   2.0.0
     */
    public function charge(string $siteIdentifier, int $bytes, DateTimeImmutable $at): void
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('An export byte charge cannot be negative.');
        }
        if ($bytes === 0) {
            return;
        }
        if ($bytes > $this->bytesPerDay) {
            throw $this->exhausted($siteIdentifier);
        }
        $utc = $at->setTimezone(new DateTimeZone('UTC'));
        $window = $utc->setTime(0, 0);
        $table = $this->tables->quoted('business_report_export_site_budgets');
        $platform = $this->database->getDatabasePlatform();
        $this->database->executeStatement(sprintf(
            'INSERT INTO %s (site_identifier, window_start, window_bytes, updated_at) VALUES (?, ?, 0, ?) %s',
            $table,
            $platform instanceof AbstractMySQLPlatform
                ? 'ON DUPLICATE KEY UPDATE site_identifier = site_identifier'
                : 'ON CONFLICT (site_identifier) DO NOTHING',
        ), [$siteIdentifier, $window, $utc], [
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
        ]);
        $charged = $this->database->executeStatement(sprintf(
            'UPDATE %s SET window_bytes = CASE WHEN window_start = ? THEN window_bytes + ? ELSE ? END, '
            . 'window_start = ?, updated_at = ? WHERE site_identifier = ? '
            . 'AND CASE WHEN window_start = ? THEN window_bytes + ? ELSE ? END <= ?',
            $table,
        ), [$window, $bytes, $bytes, $window, $utc, $siteIdentifier, $window, $bytes, $bytes, $this->bytesPerDay], [
            Types::DATETIME_IMMUTABLE,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
            Types::DATETIME_IMMUTABLE,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
        ]);
        if ($charged < 1) {
            throw $this->exhausted($siteIdentifier);
        }
    }

    /**
     * Build the refusal for one site.
     *
     * @param   string  $siteIdentifier  Site.
     *
     * @return  ExportSiteByteBudgetExhausted  Refusal.
     *
     * @since   2.0.0
     */
    private function exhausted(string $siteIdentifier): ExportSiteByteBudgetExhausted
    {
        return new ExportSiteByteBudgetExhausted(sprintf(
            'Site %s has no export byte budget left for this UTC day (%d bytes a day).',
            $siteIdentifier,
            $this->bytesPerDay,
        ));
    }
}
