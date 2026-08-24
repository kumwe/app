<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDraftSource;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewDraft;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use RuntimeException;
use stdClass;

/**
 * AP-4 adapter that resolves exact immutable Blueprint bytes through AP-6's narrow draft-source port.
 *
 * @since  2.0.0
 */
final readonly class DoctrineStudioPreviewDraftSource implements StudioPreviewDraftSource
{
    /**
     * Bind artifact reads to the prefix-aware database and pinned Blueprint schema.
     *
     * @param  Connection             $database  Database containing immutable AP-4 revisions.
     * @param  TableNames             $tables    Prefix-aware table compiler.
     * @param  StudioContractSchemas  $schemas   Exact vendored protocol schema registry.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private StudioContractSchemas $schemas,
    ) {
    }

    /**
     * Resolve a unique site/artifact/revision tuple and re-prove its canonical bytes and schema.
     *
     * @param   StudioHostSessionSnapshot   $snapshot  Live trusted Studio authority.
     * @param   StudioPreviewRenderRequest  $request   Exact render identity.
     *
     * @return  StudioPreviewDraft|null  Canonical Blueprint or null without disclosing its absence reason.
     *
     * @throws  RuntimeException  When persisted bytes are corrupt or an identity is unexpectedly ambiguous.
     *
     * @since   2.0.0
     */
    public function find(
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewRenderRequest $request,
    ): ?StudioPreviewDraft {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT artifact_kind, canonical_document FROM %s WHERE site_identifier = ? '
                . 'AND artifact_id = ? AND revision = ?',
            $this->tables->quoted('studio_artifact_revisions'),
        ), [$snapshot->session->siteId, $request->artifactId, $request->draftRevision]);
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw new RuntimeException('A Studio preview artifact revision is ambiguous.');
        }
        $kind = $rows[0]['artifact_kind'] ?? null;
        $canonical = $rows[0]['canonical_document'] ?? null;
        if ($kind !== 'blueprint' || !is_string($canonical)) {
            return null;
        }
        try {
            $document = json_decode($canonical, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('A stored Studio preview draft is unreadable.', 0, $exception);
        }
        if (
            !$document instanceof stdClass
            || !$this->schemas->validator('blueprint')->validate($document)
            || !hash_equals($canonical, CanonicalJson::stringify($document))
        ) {
            throw new RuntimeException('A stored Studio preview draft is not canonical and schema-valid.');
        }

        return new StudioPreviewDraft($snapshot->session->siteId, $document);
    }
}
