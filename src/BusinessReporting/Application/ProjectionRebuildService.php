<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Application;

use RuntimeException;
use Throwable;
use Kumwe\App\BusinessReporting\Domain\ProjectionDefinition;

/**
 * Replays a versioned event stream into an atomically replaceable derived generation.
 *
 * @since  2.0.0
 */
final readonly class ProjectionRebuildService
{
    /**
     * Wire the deterministic event source, builder and replacement writer.
     *
     * @param  ProjectionEventSource  $events   Ordered immutable source.
     * @param  ProjectionBuilder      $builder  Pure event-to-row logic.
     * @param  ProjectionWriter       $writer   Atomic replacement store.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ProjectionEventSource $events,
        private ProjectionBuilder $builder,
        private ProjectionWriter $writer,
    ) {
    }

    /**
     * Rebuild from sequence zero and return enough evidence to reproduce and compare the result.
     *
     * @param   ProjectionDefinition  $definition  Exact projection contract to rebuild.
     *
     * @return  ProjectionRebuildResult  Terminal sequence and source/output checksums.
     *
     * @throws  RuntimeException  When the event source violates strict ordering or declared source versions.
     * @throws  Throwable  When the builder or writer fails; the replacement generation is rolled back.
     *
     * @since   2.0.0
     */
    public function rebuild(ProjectionDefinition $definition): ProjectionRebuildResult
    {
        $this->writer->begin($definition);
        $sequence = 0;
        $count = 0;
        $sourceChecksum = hash('sha256', $definition->checksum());
        try {
            do {
                $page = $this->events->next($definition, $sequence, $definition->rebuildBatchSize);
                if (count($page) > $definition->rebuildBatchSize) {
                    throw new RuntimeException('A projection event source exceeded its requested batch.');
                }
                foreach ($page as $event) {
                    if (!$event instanceof ProjectionEvent || $event->sequence <= $sequence) {
                        throw new RuntimeException('Projection events must be strictly sequence ordered.');
                    }
                    $this->assertDeclared($definition, $event);
                    $this->builder->apply($definition, $event, $this->writer);
                    $sequence = $event->sequence;
                    ++$count;
                    $sourceChecksum = hash('sha256', $sourceChecksum . "\n" . $event->checksum());
                    $this->writer->checkpoint($sequence, $sourceChecksum);
                }
            } while ($page !== []);
            $projectionChecksum = $this->writer->commit();
            if (preg_match('/^[0-9a-f]{64}$/D', $projectionChecksum) !== 1) {
                throw new RuntimeException('A projection writer returned an invalid checksum.');
            }

            return new ProjectionRebuildResult($sequence, $count, $sourceChecksum, $projectionChecksum);
        } catch (Throwable $exception) {
            $this->writer->rollback();
            throw $exception;
        }
    }

    /**
     * Require the event to match the projection source declaration.
     *
     * @param   ProjectionDefinition  $definition  Signed contribution definition governing the operation.
     * @param   ProjectionEvent       $event       Versioned event being validated or processed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertDeclared(ProjectionDefinition $definition, ProjectionEvent $event): void
    {
        foreach ($definition->sources as $source) {
            if (
                $source->eventType === $event->type
                && in_array($event->schemaVersion, $source->schemaVersions, true)
            ) {
                return;
            }
        }
        throw new RuntimeException('A projection event type or schema version is undeclared.');
    }
}
