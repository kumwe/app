<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use JsonException;
use Kumwe\App\Extension\Application\Trust\RevocationFeedSynchronizer;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Psr\Clock\ClockInterface;

/**
 * Publishes the recovery, storage and extension-trust gauges that no application table holds.
 *
 * Backups and restores run as shell tools outside this process, so their outcome reaches the scrape through
 * the small status documents `tools/recovery-common.sh` writes atomically into the operations status
 * directory; storage headroom is read from the filesystem that holds each volume; extension trust is the
 * verdict this process loaded its runtime under plus the recorded revocation feed state. Each read is a
 * constant-cost call, so the scrape stays bounded however much data the installation holds.
 *
 * A status document that is absent publishes zero, which the backup-age alert reads as "never", not as
 * "recent". A document that is unreadable or malformed is treated the same way rather than failing the
 * scrape, because a monitoring endpoint that raises turns a missing file into a paging outage of its own.
 *
 * @since  2.0.0
 */
final readonly class OperationalStatusCollector implements MetricCollector
{
    /**
     * Largest status document read, in bytes; anything larger is not a document the tools wrote.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int MAXIMUM_STATUS_BYTES = 65_536;

    /**
     * Bind the collector to the directory, volumes and trust sources it reads.
     *
     * @param  string                        $statusDirectory  Directory the recovery tools record outcomes in.
     * @param  array<string, string>         $volumes          Absolute path per declared storage volume label.
     * @param  ClockInterface                $clock            Reading revocation staleness is judged against.
     * @param  ?RuntimeMaterializationState  $runtime          Runtime generation this process loaded, or null to
     *         leave the trust gauge out.
     * @param  ?RevocationFeedSynchronizer   $revocation       Source of the recorded revocation feed state, or
     *         null to leave the feed gauges out.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $statusDirectory,
        private array $volumes,
        private ClockInterface $clock,
        private ?RuntimeMaterializationState $runtime = null,
        private ?RevocationFeedSynchronizer $revocation = null,
    ) {
    }

    /**
     * Collect every recovery, storage and trust gauge.
     *
     * @return  list<MetricSample>  The gauge samples.
     *
     * @since   2.0.0
     */
    public function collect(): array
    {
        $samples = [];
        foreach (MetricCatalog::RECOVERY_OPERATIONS as $operation) {
            $status = $this->status($operation);
            foreach (['success', 'failure'] as $outcome) {
                $name = sprintf('kumwe_recovery_last_%s_timestamp_seconds', $outcome);
                $value = $status['last_' . $outcome . '_at'] ?? null;
                $samples[] = new MetricSample(
                    $name,
                    $name,
                    ['operation' => $operation],
                    is_int($value) && $value > 0 ? (float) $value : 0.0,
                );
            }
        }
        foreach ($this->volumes as $volume => $path) {
            if (!in_array($volume, MetricCatalog::VOLUMES, true) || !is_dir($path)) {
                continue;
            }
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            if ($free === false || $total === false) {
                continue;
            }
            $samples[] = new MetricSample('kumwe_storage_free_bytes', 'kumwe_storage_free_bytes', [
                'volume' => $volume,
            ], $free);
            $samples[] = new MetricSample('kumwe_storage_capacity_bytes', 'kumwe_storage_capacity_bytes', [
                'volume' => $volume,
            ], $total);
        }
        if ($this->runtime !== null) {
            $trusted = $this->runtime->trusted && $this->runtime->generation >= 0;
            $samples[] = self::gauge('kumwe_extension_runtime_trusted', $trusted ? 1.0 : 0.0);
        }
        if ($this->revocation !== null) {
            $state = $this->revocation->state();
            $samples[] = self::gauge(
                'kumwe_extension_revocation_feed_stale',
                $state->isStale($this->clock->now()) ? 1.0 : 0.0,
            );
            $samples[] = self::gauge('kumwe_extension_revocation_feed_failures', (float) $state->consecutiveFailures);
        }

        return $samples;
    }

    /**
     * Read one recorded operation outcome, treating anything unreadable as never recorded.
     *
     * @param   string  $operation  Declared recovery operation.
     *
     * @return  array<string, mixed>  The decoded document, or an empty array.
     *
     * @since   2.0.0
     */
    private function status(string $operation): array
    {
        $path = $this->statusDirectory . '/' . $operation . '.json';
        if (!is_file($path) || is_link($path) || (int) @filesize($path) > self::MAXIMUM_STATUS_BYTES) {
            return [];
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents)) {
            return [];
        }
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (
            !is_array($decoded)
            || ($decoded['schema'] ?? null) !== 'kumwe-operation-status/v1'
            || ($decoded['operation'] ?? null) !== $operation
        ) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Build one unlabelled gauge sample.
     *
     * @param   string  $name   Declared gauge family name.
     * @param   float   $value  Current value.
     *
     * @return  MetricSample  The sample.
     *
     * @since   2.0.0
     */
    private static function gauge(string $name, float $value): MetricSample
    {
        return new MetricSample($name, $name, [], $value);
    }
}
