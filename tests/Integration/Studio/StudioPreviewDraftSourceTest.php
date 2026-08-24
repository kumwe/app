<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use Doctrine\DBAL\DriverManager;
use JsonException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioPreviewDraftSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Proves AP-6 reads exact AP-4 immutable bytes through its narrow draft-source port.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineStudioPreviewDraftSource::class)]
final class StudioPreviewDraftSourceTest extends TestCase
{
    /**
     * Site, artifact and revision predicates return canonical Blueprint bytes without a parallel store.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed vector is invalid.
     *
     * @since  2.0.0
     */
    public function testExactImmutableArtifactRevisionIsResolvedAndReproved(): void
    {
        $vector = self::vector();
        $document = $vector->draft ?? null;
        $render = $vector->render ?? null;
        if (
            !$document instanceof stdClass
            || !$render instanceof stdClass
            || !is_string($document->version ?? null)
            || !is_string($render->artifactId ?? null)
            || !is_string($render->draftRevision ?? null)
            || !is_string($render->draftDigest ?? null)
        ) {
            throw new RuntimeException('The Studio preview vector has an invalid identity shape.');
        }
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $database->executeStatement(sprintf(
            'CREATE TABLE %s (site_identifier VARCHAR(191) NOT NULL, artifact_id VARCHAR(240) NOT NULL, '
                . 'artifact_version VARCHAR(100) NOT NULL, artifact_kind VARCHAR(20) NOT NULL, '
                . 'revision VARCHAR(200) NOT NULL, canonical_document TEXT NOT NULL, '
                . 'PRIMARY KEY (site_identifier, artifact_id, artifact_version, revision))',
            $tables->quoted('studio_artifact_revisions'),
        ));
        $canonical = CanonicalJson::stringify($document);
        $database->insert($tables->raw('studio_artifact_revisions'), [
            'site_identifier' => 'default',
            'artifact_id' => $render->artifactId,
            'artifact_version' => $document->version,
            'artifact_kind' => 'blueprint',
            'revision' => $render->draftRevision,
            'canonical_document' => $canonical,
        ]);
        $source = new DoctrineStudioPreviewDraftSource(
            $database,
            $tables,
            StudioContractSchemas::fromVendoredCorpus(),
        );

        $draft = $source->find(self::snapshot($render->artifactId), StudioPreviewRenderRequest::fromPayload(
            $render,
        ));

        self::assertNotNull($draft);
        self::assertSame($canonical, $draft->canonical());
        self::assertSame($render->draftDigest, $draft->digest());
    }

    /**
     * Build a trusted session for the exact vector artifact.
     *
     * @param   string  $artifactId  Exact Blueprint artifact identifier.
     *
     * @return  StudioHostSessionSnapshot  Read-authorized Blueprint session.
     *
     * @since  2.0.0
     */
    private static function snapshot(string $artifactId): StudioHostSessionSnapshot
    {
        $session = new StudioHostSession(
            'contexts/draft-source',
            'actor-source',
            'default',
            null,
            null,
            'administrator',
            hash('sha256', 'draft-source-session'),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            $artifactId,
            'session-draft-source',
        );

        return new StudioHostSessionSnapshot(
            $session,
            ['studio.permission/read'],
            $session->sessionGeneration,
            true,
            false,
            false,
        );
    }

    /**
     * Decode the exact canonical-preorder preview vector.
     *
     * @return  stdClass  Decoded vector.
     *
     * @throws  JsonException  When the fixture is invalid.
     * @throws  RuntimeException  When it is not an object.
     *
     * @since  2.0.0
     */
    private static function vector(): stdClass
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/Studio/testkit/vectors/preview/canonical-preorder.json';
        $vector = json_decode((string) file_get_contents($path), false, 64, JSON_THROW_ON_ERROR);
        if (!$vector instanceof stdClass) {
            throw new RuntimeException('The Studio preview vector is invalid.');
        }

        return $vector;
    }
}
