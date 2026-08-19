<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Application;

use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;

/**
 * Progress report from one bounded pass of a chunked schema rewrite.
 *
 * Backfill, transform, and record-repin steps rewrite stored rows rather than table shape, so they run
 * in batches instead of one statement across the whole table. Each batch answers with this value: how
 * many rows it changed, whether anything is left to visit, and the keyset position the next batch must
 * resume from. `BusinessSchemaExecutor` checkpoints that position onto the plan step before looping, so
 * an interrupted rewrite continues where it stopped, and it treats a batch that reports neither
 * completion nor progress as a stalled rewrite rather than looping forever. Construction is what makes
 * the cursor worth trusting: an unfinished batch may not omit one, and the cursor must survive canonical
 * encoding because it is written into the durable journal.
 *
 * @since  2.0.0
 */
final readonly class SchemaChunkResult
{
    /**
     * Keyset position the next batch resumes from; null only on a batch that reports completion.
     *
     * @var    array<string, bool|int|string>|null
     * @since  2.0.0
     */
    public ?array $cursor;

    /**
     * Record one rewrite batch and prove it either finished or left somewhere to resume from.
     *
     * @param   array<string, bool|int|string>|null  $cursor     Where this batch stopped reading.
     * @param   int                                  $processed  Rows this batch rewrote; zero when none matched.
     * @param   bool                                 $complete   Whether no rows are left for a further batch.
     *
     * @throws  \InvalidArgumentException  When the processed count is negative or above 10,000, or an
     *          unfinished batch carries no cursor to resume from.
     * @throws  \Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition  When the cursor holds a
     *          value the canonical encoder refuses, so the journal could not store it.
     *
     * @since   2.0.0
     */
    public function __construct(?array $cursor, public int $processed, public bool $complete)
    {
        if ($processed < 0 || $processed > 10_000 || (!$complete && $cursor === null)) {
            throw new \InvalidArgumentException('A schema chunk result has invalid progress.');
        }
        if ($cursor !== null) {
            CanonicalDefinitionJson::encode($cursor);
        }
        $this->cursor = $cursor;
    }
}
