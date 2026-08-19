<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaConflict;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaRecordRepinGateway;
use Kumwe\App\BusinessSchema\Application\SchemaChunkResult;
use Kumwe\App\BusinessSchema\Domain\InvalidBusinessSchema;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\SchemaOperation;
use Kumwe\App\BusinessSchema\Domain\SchemaOperationKind;

/**
 * Revalidates and rewrites exact typed rows under the schema executor's database fence.
 *
 * Advancing a definition version is only honest once every stored row still satisfies the definition it
 * is about to be pinned to, and this gateway is what proves that, one chunk at a time, for the
 * `RepinRecords` step of a schema plan. Each row is decoded through `RecordValueCodec`, re-run through
 * `RecordRuleValidator` against the target definition, re-encoded, and written back with the new
 * definition version in the same statement — a row that no longer validates aborts the plan rather than
 * being silently left behind. Every update is guarded by the row's optimistic version and by its old
 * definition version, so a concurrent writer is detected instead of overwritten and a chunk that runs
 * again after a resume simply finds nothing left to do. Transactions, journaling and the fence belong
 * to `BusinessSchemaExecutor`, which also owns the keyset cursor this gateway returns and feeds back.
 *
 * @since  2.0.0
 */
final readonly class DoctrineBusinessSchemaRecordRepinGateway implements BusinessSchemaRecordRepinGateway
{
    /**
     * Bind the gateway to the connection and the two collaborators a revalidating rewrite needs.
     *
     * @param  Connection           $database  DBAL connection the record rows are read from and rewritten
     *         on, inside whatever scope the executor has already opened around the chunk.
     * @param  RecordValueCodec     $values    Codec turning stored columns into field values and validated
     *         values back into columns, secret fields included.
     * @param  RecordRuleValidator  $rules     Validator re-running the target definition's field rules over a
     *         decoded row.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private RecordValueCodec $values,
        private RecordRuleValidator $rules,
    ) {
    }

    /**
     * Revalidate and rewrite one keyset-ordered chunk of rows onto the target definition version.
     *
     * The read takes only rows still below the target version, in identity order, resuming after the
     * cursor's `last_identity`; every row it returns is rewritten before the method returns, so the
     * cursor handed back names exactly as far as the rewrite reached. The chunk reports itself complete
     * once the read returns fewer rows than the limit.
     *
     * @param   EntityTypeDefinition                 $definition  Target definition rows must satisfy; its id, version
     *          and checksum have to agree with the target blueprint.
     * @param   SchemaOperation                      $operation   Plan step being executed; must be a `RepinRecords`
     *          step naming the table and carrying the target version in `after['definition_version']`.
     * @param   PhysicalSchemaBlueprint              $target      Compiled physical schema for that definition version,
     *          supplying the installed table and column names.
     * @param   array<string, bool|int|string>|null  $cursor      Keyset position of the previous chunk under
     *          `last_identity`, or null to start from the first row.
     * @param   int                                  $limit       Most rows this chunk may rewrite, from 1 to 1000.
     *
     * @return  SchemaChunkResult  Rows rewritten, the `last_identity` cursor the next chunk resumes from —
     *          the position this chunk started at when it rewrote nothing — and whether the table is
     *          exhausted.
     *
     * @throws  InvalidBusinessSchema  When the operation is not a bounded record repin, the definition
     *          disagrees with the target blueprint, the table is missing or has other than one identity
     *          column, its definition-version or optimistic-version column is absent, the target version is
     *          missing, below two, or disagrees with the definition's own, the cursor holds no usable
     *          identity, or an encoded column has no blueprint entry.
     * @throws  BusinessSchemaConflict  When a row's identity or version metadata is unusable, a row no longer
     *          satisfies the target definition, or the guarded update matches no row because the record
     *          changed underneath the chunk.
     * @throws  \Doctrine\DBAL\Exception  When the driver rejects the chunk read or one of its updates.
     *
     * @since   2.0.0
     */
    public function repinChunk(
        EntityTypeDefinition $definition,
        SchemaOperation $operation,
        PhysicalSchemaBlueprint $target,
        ?array $cursor,
        int $limit,
    ): SchemaChunkResult {
        if ($operation->kind !== SchemaOperationKind::RepinRecords || $limit < 1 || $limit > 1_000) {
            throw new InvalidBusinessSchema('A schema record-repin chunk request is invalid.');
        }
        if (
            $definition->id !== $target->definitionId
            || $definition->definitionVersion !== $target->definitionVersion
            || !hash_equals($definition->checksum(), $target->definitionChecksum)
        ) {
            throw new InvalidBusinessSchema('A record-repin definition differs from its target blueprint.');
        }
        $table = $target->table($operation->table)
            ?? throw new InvalidBusinessSchema('A record-repin operation has no target table.');
        if (count($table->primaryKey) !== 1) {
            throw new InvalidBusinessSchema('Chunked record repinning requires one canonical identity column.');
        }
        $toVersion = $operation->after['definition_version'] ?? null;
        if (!is_int($toVersion) || $toVersion < 2 || $toVersion !== $definition->definitionVersion) {
            throw new InvalidBusinessSchema('A record-repin target version is invalid.');
        }
        $identity = $this->physicalColumn($table, $table->primaryKey[0]);
        $definitionVersion = $table->column('definition_version')
            ?? throw new InvalidBusinessSchema('A record-repin table has no definition-version column.');
        $recordVersion = $table->column('version')
            ?? throw new InvalidBusinessSchema('A record-repin table has no optimistic-version column.');
        $last = $cursor['last_identity'] ?? null;
        if ($cursor !== null && !is_int($last) && !is_string($last)) {
            throw new InvalidBusinessSchema('A record-repin cursor is invalid.');
        }

        $parameters = [$toVersion];
        $types = [$definitionVersion->doctrineType];
        $cursorPredicate = '';
        if ($last !== null) {
            $cursorPredicate = sprintf(' AND %s > ?', $this->quote($identity->physicalName));
            $parameters[] = $last;
            $types[] = $identity->doctrineType;
        }
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE %s < ?%s ORDER BY %s LIMIT %d',
            $this->quote($table->physicalName),
            $this->quote($definitionVersion->physicalName),
            $cursorPredicate,
            $this->quote($identity->physicalName),
            $limit,
        ), $parameters, $types);

        $processed = 0;
        foreach ($rows as $row) {
            $identityValue = $row[$identity->physicalName] ?? null;
            $optimisticVersion = $row[$recordVersion->physicalName] ?? null;
            if (
                (!is_int($identityValue) && !is_string($identityValue))
                || (!is_int($optimisticVersion)
                    && (!is_string($optimisticVersion)
                        || preg_match('/^[1-9][0-9]*$/D', $optimisticVersion) !== 1))
            ) {
                throw new BusinessSchemaConflict('A record-repin row has invalid concurrency metadata.');
            }
            $recordKey = (string) $identityValue;
            try {
                $decoded = $this->values->decodeColumns(
                    $definition,
                    $table,
                    $row,
                    $definition->siteIdentifier,
                    $recordKey,
                );
                $recordId = $this->values->publicIdentity($definition, $recordKey, $decoded);
                $validated = $this->rules->repin(
                    $definition,
                    $decoded,
                    $definition->siteIdentifier,
                    $recordKey,
                    $recordId,
                );
                $this->assertWorkflowState($definition, $table, $row);
            } catch (BusinessRecordValidationFailed | InvalidBusinessSchema | \InvalidArgumentException $failure) {
                throw new BusinessSchemaConflict(
                    'A pinned record violates the target definition and cannot be repinned.',
                    0,
                    $failure,
                );
            }
            $encoded = $this->values->encodeColumns($definition, $table, $validated);
            $encoded[$definitionVersion->physicalName] = $toVersion;
            ksort($encoded, SORT_STRING);
            $assignments = [];
            $updateValues = [];
            $updateTypes = [];
            foreach ($encoded as $columnName => $value) {
                $column = $this->physicalColumn($table, $columnName);
                $assignments[] = $this->quote($columnName) . ' = ?';
                $updateValues[] = $value;
                $updateTypes[] = $column->doctrineType;
            }
            $updateValues[] = $identityValue;
            $updateTypes[] = $identity->doctrineType;
            $updateValues[] = $toVersion;
            $updateTypes[] = $definitionVersion->doctrineType;
            $updateValues[] = (int) $optimisticVersion;
            $updateTypes[] = $recordVersion->doctrineType;
            $affected = $this->database->executeStatement(sprintf(
                'UPDATE %s SET %s WHERE %s = ? AND %s < ? AND %s = ?',
                $this->quote($table->physicalName),
                implode(', ', $assignments),
                $this->quote($identity->physicalName),
                $this->quote($definitionVersion->physicalName),
                $this->quote($recordVersion->physicalName),
            ), $updateValues, $updateTypes);
            if ($affected !== 1) {
                throw new BusinessSchemaConflict('A record changed concurrently during explicit schema repinning.');
            }
            $last = $identityValue;
            ++$processed;
        }

        return new SchemaChunkResult(
            $last === null ? $cursor : ['last_identity' => $last],
            $processed,
            count($rows) < $limit,
        );
    }

    /**
     * Prove the row's stored workflow state survives into the target definition.
     *
     * Rules validation covers field values but not the workflow column, which is why this runs beside it.
     * A table without a workflow-state column passes, as does a row with no state under a definition that
     * declares no workflow; every other combination has to name one of the target workflow's states.
     *
     * @param   EntityTypeDefinition    $definition  Target definition whose workflow lists the acceptable
     *          states, or declares no workflow at all.
     * @param   PhysicalTableBlueprint  $table       Blueprint locating the `workflow_state` column when the
     *          table carries one.
     * @param   array<string, mixed>    $row         Row as read from the table, keyed by installed column
     *          name.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When the stored state is not one the target workflow declares, when the
     *          target declares a workflow but the row holds no state, or when a state is stored for a target
     *          that has dropped its workflow.
     *
     * @since   2.0.0
     */
    private function assertWorkflowState(
        EntityTypeDefinition $definition,
        PhysicalTableBlueprint $table,
        array $row,
    ): void {
        $column = $table->column('workflow_state');
        if ($column === null) {
            return;
        }
        $state = $row[$column->physicalName] ?? null;
        if ($state === null && $definition->workflow === null) {
            return;
        }
        if (
            !is_string($state) || $definition->workflow === null
            || !in_array($state, $definition->workflow->states, true)
        ) {
            throw new InvalidBusinessSchema('A pinned record uses a workflow state absent from the target.');
        }
    }

    /**
     * Resolve a column blueprint from the installed name a row or an update uses.
     *
     * Every value this gateway binds needs the column's DBAL type, so a name the blueprint does not know
     * is a schema error rather than a column to skip; that is the difference from the blueprint's own
     * lookup, which answers null.
     *
     * @param   PhysicalTableBlueprint  $table         Blueprint of the table being repinned.
     * @param   string                  $physicalName  Installed column name, as it appears in the fetched row
     *          and in the encoded update.
     *
     * @return  PhysicalColumnBlueprint  The matching column, carrying the DBAL type its parameter binds with.
     *
     * @throws  InvalidBusinessSchema  When the table declares no column under that installed name.
     *
     * @since   2.0.0
     */
    private function physicalColumn(
        PhysicalTableBlueprint $table,
        string $physicalName,
    ): PhysicalColumnBlueprint {
        foreach ($table->columns() as $column) {
            if ($column->physicalName === $physicalName) {
                return $column;
            }
        }

        throw new InvalidBusinessSchema('A record-repin physical column is unavailable.');
    }

    /**
     * Quote one identifier for the connected platform.
     *
     * Table and column names reach the statements above from the blueprint rather than from a request, and
     * quoting them keeps a name that collides with a reserved word usable on every supported engine.
     *
     * @param   string  $identifier  Single installed table or column name, never a dotted path.
     *
     * @return  string  The identifier quoted the way the connected driver expects.
     *
     * @throws  \Doctrine\DBAL\Exception  When the platform to quote for cannot be resolved.
     *
     * @since   2.0.0
     */
    private function quote(string $identifier): string
    {
        return $this->database->quoteSingleIdentifier($identifier);
    }
}
