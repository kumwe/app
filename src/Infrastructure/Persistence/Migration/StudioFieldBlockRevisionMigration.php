<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\Producer\Canonical\CanonicalJson;
use RuntimeException;
use stdClass;

/**
 * Move stored Content-field block locks from `core-block-r1` to `core-block-r2` (App ADR 0024).
 *
 * Revision r2 of the nine core Content-field blocks adds the inspector control their value port names and
 * changes nothing a renderer reads, but a Blueprint locked at r1 is refused by the contribution catalogue's
 * exact lock check and has no live renderer. This forward-only, repeatable migration rewrites only the
 * field-block lock revisions inside stored Blueprint documents and their canonical dependency lists, in
 * both the revision history and the current heads, and proves every rewritten row still carries canonical
 * bytes for its unchanged identity. Revision identities, recovery envelopes, replay results and preview
 * grants are left untouched. A second run finds no r1 field lock and changes nothing.
 *
 * @since  2.0.0
 */
final readonly class StudioFieldBlockRevisionMigration implements RepeatableMigration
{
    /**
     * Stable ordered migration identity, appended after the core listing sort indexes.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ID = '20261007120000_studio_field_block_revision';

    /**
     * Content-field block types whose lock revision this migration moves, fixed as released.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FIELD_TYPES = [
        'core/field-text',
        'core/field-rich-text',
        'core/field-integer',
        'core/field-decimal',
        'core/field-boolean',
        'core/field-date',
        'core/field-date-time',
        'core/field-media',
        'core/field-resource',
    ];

    /**
     * Block version both revisions share.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string FIELD_VERSION = '1.0.0';

    /**
     * Lock revision the stored Blueprints carry before this migration.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PREVIOUS_REVISION = 'core-block-r1';

    /**
     * Lock revision the stored Blueprints carry after this migration.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string NEXT_REVISION = 'core-block-r2';

    /**
     * Logical artifact tables rewritten, the immutable history before the current heads.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array TABLES = ['studio_artifact_revisions', 'studio_artifact_heads'];

    /**
     * Bind the migration to the installation's prefix-aware table names.
     *
     * @param  TableNames  $tables  Physical table-name compiler.
     *
     * @since  2.0.0
     */
    public function __construct(private TableNames $tables)
    {
    }

    /**
     * Return the append-only migration identity stored in the schema ledger.
     *
     * @return  string  Stable ordered migration identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return self::ID;
    }

    /**
     * Bind applied history to these exact migration bytes.
     *
     * @return  string  SHA-256 migration checksum.
     *
     * @throws  RuntimeException  When the source digest cannot be read.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The Studio field block revision migration checksum is unavailable.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    /**
     * Rewrite every stored Blueprint that still locks a Content-field block at `core-block-r1`.
     *
     * @param   Connection  $database  Installation database holding the Studio artifact tables.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a rewritten artifact no longer proves its canonical identity.
     * @throws  \JsonException  When a stored artifact or dependency list is not valid JSON.
     * @throws  \Doctrine\DBAL\Exception  When a row cannot be read or updated.
     * @throws  \Kumwe\Producer\Canonical\CanonicalEncodingException  When a rewritten document or dependency list
     *          cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    public function up(Connection $database): void
    {
        foreach (self::TABLES as $logical) {
            $table = $this->tables->quoted($logical);
            $rows = $database->fetchAllAssociative(sprintf(
                'SELECT site_identifier, artifact_id, artifact_version, artifact_kind, revision, status,'
                . ' canonical_document, canonical_dependencies FROM %s'
                . ' WHERE artifact_kind = ? AND canonical_document LIKE ?',
                $table,
            ), ['blueprint', '%"' . self::PREVIOUS_REVISION . '"%']);
            foreach ($rows as $row) {
                $this->rewrite($database, $table, $row);
            }
        }
    }

    /**
     * Rewrite one stored Blueprint row when it locks a Content-field block at the previous revision.
     *
     * @param   Connection            $database  Installation database.
     * @param   string                $table     Quoted physical artifact table name.
     * @param   array<string, mixed>  $row       Stored artifact row.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the row is malformed or the rewritten artifact is not canonical.
     * @throws  \JsonException  When the stored bytes are not valid JSON.
     * @throws  \Doctrine\DBAL\Exception  When the row cannot be updated.
     * @throws  \Kumwe\Producer\Canonical\CanonicalEncodingException  When a rewritten document or dependency list
     *          cannot be canonically encoded.
     *
     * @since   2.0.0
     */
    private function rewrite(Connection $database, string $table, array $row): void
    {
        $site = self::column($row, 'site_identifier');
        $id = self::column($row, 'artifact_id');
        $version = self::column($row, 'artifact_version');
        $revision = self::column($row, 'revision');
        $document = CanonicalJson::decode(self::column($row, 'canonical_document'));
        $dependencies = CanonicalJson::decode(self::column($row, 'canonical_dependencies'));
        if (!$document instanceof stdClass || !is_array($dependencies)) {
            throw new RuntimeException('A stored Studio Blueprint row is malformed.');
        }
        $locksMoved = self::rewriteLocks($document);
        $dependenciesMoved = self::rewriteDependencies($dependencies);
        if (!$locksMoved && !$dependenciesMoved) {
            return;
        }
        $artifact = new StoredStudioArtifact(
            $site,
            $id,
            $version,
            self::column($row, 'artifact_kind'),
            $revision,
            self::column($row, 'status'),
            CanonicalJson::stringify($document),
            CanonicalJson::stringify(self::canonicalOrder($dependencies)),
        );
        $database->executeStatement(sprintf(
            'UPDATE %s SET canonical_document = ?, canonical_dependencies = ?'
            . ' WHERE site_identifier = ? AND artifact_id = ? AND artifact_version = ? AND revision = ?',
            $table,
        ), [$artifact->canonicalDocument, $artifact->canonicalDependencies, $site, $id, $version, $revision]);
    }

    /**
     * Move the previous-revision Content-field block locks of one Blueprint document in place.
     *
     * @param   stdClass  $document  Decoded Blueprint document.
     *
     * @return  bool  True when at least one lock moved.
     *
     * @since   2.0.0
     */
    private static function rewriteLocks(stdClass $document): bool
    {
        $lock = $document->dependencyLock ?? null;
        $blocks = $lock instanceof stdClass ? ($lock->blocks ?? null) : null;
        if (!is_array($blocks)) {
            return false;
        }
        $moved = false;
        foreach ($blocks as $block) {
            if ($block instanceof stdClass && self::isPreviousFieldLock($block->type ?? null, $block)) {
                $block->revision = self::NEXT_REVISION;
                $moved = true;
            }
        }

        return $moved;
    }

    /**
     * Move the previous-revision Content-field block references of one dependency list in place.
     *
     * @param   array<mixed>  $dependencies  Decoded canonical `{id, version, revision}` reference list.
     *
     * @return  bool  True when at least one reference moved.
     *
     * @since   2.0.0
     */
    private static function rewriteDependencies(array $dependencies): bool
    {
        $moved = false;
        foreach ($dependencies as $dependency) {
            if ($dependency instanceof stdClass && self::isPreviousFieldLock($dependency->id ?? null, $dependency)) {
                $dependency->revision = self::NEXT_REVISION;
                $moved = true;
            }
        }

        return $moved;
    }

    /**
     * Decide whether one lock or reference names a Content-field block at the previous revision.
     *
     * @param   mixed     $type       Block type the entry names.
     * @param   stdClass  $reference  Lock or dependency entry.
     *
     * @return  bool  True for an exact `1.0.0` Content-field lock at `core-block-r1`.
     *
     * @since   2.0.0
     */
    private static function isPreviousFieldLock(mixed $type, stdClass $reference): bool
    {
        return is_string($type)
            && in_array($type, self::FIELD_TYPES, true)
            && ($reference->version ?? null) === self::FIELD_VERSION
            && ($reference->revision ?? null) === self::PREVIOUS_REVISION;
    }

    /**
     * Deduplicate and order dependency references by their canonical bytes, as artifact admission does.
     *
     * @param   array<mixed>  $dependencies  Decoded dependency references.
     *
     * @return  list<mixed>  References in canonical byte order.
     *
     * @since   2.0.0
     */
    private static function canonicalOrder(array $dependencies): array
    {
        $unique = [];
        foreach ($dependencies as $dependency) {
            $unique[CanonicalJson::stringify($dependency)] = $dependency;
        }
        ksort($unique, SORT_STRING);

        return array_values($unique);
    }

    /**
     * Read one required string column of a stored artifact row.
     *
     * @param   array<string, mixed>  $row     Stored artifact row.
     * @param   string                $column  Column name.
     *
     * @return  string  Column value.
     *
     * @throws  RuntimeException  When the column is absent or not a string.
     *
     * @since   2.0.0
     */
    private static function column(array $row, string $column): string
    {
        $value = $row[$column] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('A stored Studio Blueprint row is malformed.');
        }

        return $value;
    }
}
