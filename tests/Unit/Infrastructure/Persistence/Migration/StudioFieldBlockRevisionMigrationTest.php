<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Infrastructure\Persistence\Migration\CoreListingSortIndexMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\RepeatableMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioArtifactRecoveryMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioFieldBlockRevisionMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\Producer\Canonical\CanonicalJson;
use Kumwe\Producer\Schema\StudioDocumentSchemaRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Pins the forward move of stored Content-field block locks from `core-block-r1` to `core-block-r2` (App ADR 0024).
 *
 * @since  2.0.0
 */
#[CoversClass(StudioFieldBlockRevisionMigration::class)]
#[UsesClass(StudioArtifactRecoveryMigration::class)]
#[UsesClass(StudioArtifactAdmission::class)]
#[UsesClass(StoredStudioArtifact::class)]
final class StudioFieldBlockRevisionMigrationTest extends TestCase
{
    /**
     * Blueprint every stored fixture row holds.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string BLUEPRINT_ID = 'content-blueprint:018f22e2-7c8b-7ab0-8f3a-88e8026be740';

    /**
     * The migration follows the previous append-only tail, is repeatable, and binds its ledger entry to its bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIdentityAndChecksumAreAppendOnly(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $migration = new StudioFieldBlockRevisionMigration(new TableNames($database, 'kumwe_'));

        self::assertInstanceOf(RepeatableMigration::class, $migration);
        self::assertSame('20261007120000_studio_field_block_revision', $migration->id());
        self::assertSame(StudioFieldBlockRevisionMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^\d{14}_[a-z0-9]+(?:_[a-z0-9]+)*$/D', $migration->id());
        self::assertGreaterThan(CoreListingSortIndexMigration::ID, $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        self::assertSame($migration->checksum(), $migration->checksum());
    }

    /**
     * Stored field-block locks move to r2 in both artifact tables and both columns, and stay canonical.
     *
     * Only the nine Content-field block types at `1.0.0` move. The section lock, another owner's lock that happens
     * to carry the same revision name, the revision identity and every non-Blueprint row are left as stored, and
     * a second run changes nothing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStoredFieldBlockLocksMoveToTheSecondRevisionAndStayCanonical(): void
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        (new StudioArtifactRecoveryMigration($tables))->up($database);
        $admission = new StudioArtifactAdmission(StudioDocumentSchemaRegistry::fromVendoredCorpus());
        $before = $admission->admit('default', self::blueprint('core-block-r1'));
        $expected = $admission->admit('default', self::blueprint('core-block-r2'));
        self::assertStringContainsString('"core-block-r1"', $before->canonicalDependencies);
        foreach (['studio_artifact_heads', 'studio_artifact_revisions'] as $table) {
            self::insert($database, $tables, $table, [
                'site_identifier' => $before->siteIdentifier,
                'artifact_id' => $before->id,
                'artifact_version' => $before->version,
                'artifact_kind' => $before->kind,
                'revision' => $before->revision,
                'status' => $before->status,
                'canonical_document' => $before->canonicalDocument,
                'canonical_dependencies' => $before->canonicalDependencies,
            ]);
        }
        // A non-Blueprint row whose bytes mention the old revision is never read, let alone rewritten.
        $model = CanonicalJson::stringify((object) [
            'id' => 'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be740',
            'kind' => 'content-model',
            'label' => (object) ['key' => 'kumwe.test/label', 'defaultMessage' => 'core-block-r1'],
            'revision' => 'content-model-r1',
            'status' => 'published',
        ]);
        self::assertStringContainsString('"core-block-r1"', $model);
        self::insert($database, $tables, 'studio_artifact_heads', [
            'site_identifier' => 'default',
            'artifact_id' => 'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be740',
            'artifact_version' => '0.0.1',
            'artifact_kind' => 'content-model',
            'revision' => 'content-model-r1',
            'status' => 'published',
            'canonical_document' => $model,
            'canonical_dependencies' => '[]',
        ]);
        $untouched = self::rows($database, $tables, 'content-model');

        $migration = new StudioFieldBlockRevisionMigration($tables);
        $migration->up($database);
        $migrated = self::rows($database, $tables, 'blueprint');
        $migration->up($database);

        self::assertSame($migrated, self::rows($database, $tables, 'blueprint'), 'A second run changes nothing.');
        self::assertSame($untouched, self::rows($database, $tables, 'content-model'));
        self::assertCount(2, $migrated);
        foreach ($migrated as $row) {
            self::assertSame($before->revision, $row['revision'], 'The revision identity is kept.');
            self::assertSame($before->status, $row['status']);
            self::assertSame('2026-10-07 12:00:00', $row['recorded_at']);
            // The rewritten bytes are exactly what admission produces for the same document at r2.
            self::assertSame($expected->canonicalDocument, $row['canonical_document']);
            self::assertSame($expected->canonicalDependencies, $row['canonical_dependencies']);
            $stored = new StoredStudioArtifact(
                $row['site_identifier'],
                $row['artifact_id'],
                $row['artifact_version'],
                $row['artifact_kind'],
                $row['revision'],
                $row['status'],
                $row['canonical_document'],
                $row['canonical_dependencies'],
            );
            $locks = [];
            foreach ($stored->document()->dependencyLock->blocks as $lock) {
                $locks[$lock->type . '@' . $lock->version] = $lock->revision;
            }
            self::assertSame(
                [
                    'studio.core/section@1.0.0' => 'layout-section-r1',
                    'core/field-text@1.0.0' => 'core-block-r2',
                    'core/field-integer@1.0.0' => 'core-block-r2',
                    'acme.shop/grid@1.0.0' => 'core-block-r1',
                ],
                $locks,
            );
            $dependencies = [];
            foreach ($stored->dependencies() as $dependency) {
                $dependencies[$dependency->id] = $dependency->revision;
            }
            self::assertSame('core-block-r2', $dependencies['core/field-text']);
            self::assertSame('core-block-r2', $dependencies['core/field-integer']);
            self::assertSame('layout-section-r1', $dependencies['studio.core/section']);
            self::assertSame('core-block-r1', $dependencies['acme.shop/grid']);
        }
    }

    /**
     * Build a draft Blueprint that locks the section, two Content-field blocks and another owner's block.
     *
     * @param   string  $fieldRevision  Revision both Content-field locks carry.
     *
     * @return  stdClass  Schema-valid Blueprint document.
     *
     * @since   2.0.0
     */
    private static function blueprint(string $fieldRevision): stdClass
    {
        $field = static fn (string $id, string $type, string $fieldId): stdClass => (object) [
            'id' => $id,
            'type' => $type,
            'version' => '1.0.0',
            'properties' => new stdClass(),
            'bindings' => (object) ['value' => (object) [
                'source' => (object) ['kind' => 'entry-field', 'fieldPath' => [$fieldId]],
                'transforms' => [],
                'onNull' => 'empty',
                'onError' => 'error',
            ]],
            'slots' => new stdClass(),
            'authoring' => (object) ['mode' => 'content'],
        ];

        return (object) [
            'contractVersion' => '0.1-draft',
            'kind' => 'blueprint',
            'id' => self::BLUEPRINT_ID,
            'version' => '1.0.0',
            'revision' => 'authored-' . str_repeat('c', 64),
            'owner' => (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'],
            'status' => 'draft',
            'label' => (object) ['key' => 'kumwe.app/content-blueprint', 'defaultMessage' => 'Content composition'],
            'model' => (object) [
                'id' => 'content-model:018f22e2-7c8b-7ab0-8f3a-88e8026be740',
                'version' => '0.0.1',
                'revision' => 'content-model-r1',
            ],
            'dependencyLock' => (object) [
                'theme' => (object) ['id' => 'core.theme/site', 'version' => '1.0.0', 'revision' => 'theme-r1'],
                'blocks' => [
                    (object) ['type' => 'studio.core/section', 'version' => '1.0.0', 'revision' => 'layout-section-r1'],
                    (object) ['type' => 'core/field-text', 'version' => '1.0.0', 'revision' => $fieldRevision],
                    (object) ['type' => 'core/field-integer', 'version' => '1.0.0', 'revision' => $fieldRevision],
                    (object) ['type' => 'acme.shop/grid', 'version' => '1.0.0', 'revision' => 'core-block-r1'],
                ],
            ],
            'roots' => [(object) [
                'id' => 'default/section',
                'type' => 'studio.core/section',
                'version' => '1.0.0',
                'properties' => new stdClass(),
                'bindings' => new stdClass(),
                'slots' => (object) ['content' => [
                    $field('default/field/title', 'core/field-text', 'title'),
                    $field('default/field/data:count', 'core/field-integer', 'data_count'),
                ]],
                'authoring' => (object) ['mode' => 'structural'],
            ]],
        ];
    }

    /**
     * Insert one stored artifact row recorded at a fixed instant.
     *
     * @param   Connection             $database  In-memory installation database.
     * @param   TableNames             $tables    Physical table-name compiler.
     * @param   string                 $table     Logical artifact table.
     * @param   array<string, string>  $row       Artifact columns other than the record time.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function insert(Connection $database, TableNames $tables, string $table, array $row): void
    {
        $database->insert($tables->raw($table), $row + ['recorded_at' => '2026-10-07 12:00:00']);
    }

    /**
     * Read every stored artifact row of one kind from both tables, in a stable order.
     *
     * @param   Connection  $database  In-memory installation database.
     * @param   TableNames  $tables    Physical table-name compiler.
     * @param   string      $kind      Artifact kind.
     *
     * @return  list<array<string, string>>  Stored rows, heads before revisions.
     *
     * @since   2.0.0
     */
    private static function rows(Connection $database, TableNames $tables, string $kind): array
    {
        $rows = [];
        foreach (['studio_artifact_heads', 'studio_artifact_revisions'] as $table) {
            $found = $database->fetchAllAssociative(
                sprintf(
                    'SELECT site_identifier, artifact_id, artifact_version, artifact_kind, revision, status,'
                    . ' canonical_document, canonical_dependencies, recorded_at FROM %s'
                    . ' WHERE artifact_kind = ? ORDER BY artifact_id',
                    $tables->quoted($table),
                ),
                [$kind],
            );
            foreach ($found as $row) {
                $columns = [];
                foreach ($row as $column => $value) {
                    self::assertIsString($value);
                    $columns[(string) $column] = $value;
                }
                $rows[] = $columns;
            }
        }

        return $rows;
    }
}
