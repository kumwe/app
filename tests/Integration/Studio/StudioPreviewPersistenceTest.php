<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use JsonException;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioPreviewGrantMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRenderAdmission;
use Kumwe\App\Studio\Application\Preview\StudioPreviewSequenceClaim;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderedDocument;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioPreviewRepository;
use Kumwe\Producer\Schema\StudioContractResources;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Replays both published preview host-sequence vectors through the real portable ledger.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineStudioPreviewRepository::class)]
#[CoversClass(StudioPreviewGrantMigration::class)]
final class StudioPreviewPersistenceTest extends TestCase
{
    /**
     * A same-digest cancellation in another resource context cannot suppress the original delivery.
     *
     * @return  void
     *
     * @throws  JsonException  When a committed host vector is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testCrossContextCancellationVectorDeliversTheOriginalRenderExactlyOnce(): void
    {
        [$repository, $migration, $database, $tables] = self::runtime();
        $vector = self::vector('preview.cancel.cross-context.sequence.json');
        self::assertSame('vector.host-sequence.preview.cancel.cross-context', $vector->id);
        [$renderStep, $cancelStep] = self::steps($vector);
        $renderArgument = self::renderArgument($renderStep);
        $cancelDigest = self::cancelDigest($cancelStep);
        $request = self::renderRequest($renderArgument);
        $contextA = self::snapshot(self::contextKey($renderStep), $request->artifactId);
        $contextB = self::snapshot(self::contextKey($cancelStep), $request->artifactId);
        $transport = new StudioPreviewTransport(
            'https://kumwe.test',
            'channels/vector',
            'sources/vector',
            0,
        );
        self::claimPortSequences($repository, $contextA->session->resourceContextKey, 0);
        self::claimPortSequences($repository, $contextB->session->resourceContextKey, 0);

        self::assertSame(StudioPreviewRenderAdmission::Accepted, $repository->begin(
            $contextA,
            $request,
            $transport,
            new DateTimeImmutable('2026-08-24T12:01:00+00:00'),
        ));
        $repository->cancel($contextB->session->resourceContextKey, $cancelDigest, 0);
        self::assertTrue($repository->complete(
            $contextA->session->resourceContextKey,
            $request,
            new StudioPreviewRenderedDocument(
                '<!doctype html><title>Preview</title>',
                [],
                [],
                [],
                'body{--site-accent:#0c9189;}',
            ),
        ));
        $claimed = $repository->claim(
            $contextA,
            $request->requestId,
            $transport,
            new DateTimeImmutable('2026-08-24T12:00:30+00:00'),
        );
        self::assertNotNull($claimed);
        self::assertSame('body{--site-accent:#0c9189;}', $claimed->document->stylesheet);
        self::assertSame(
            'body{--site-accent:#0c9189;}',
            $repository->claimed(
                $contextA,
                $request->requestId,
                $transport,
                new DateTimeImmutable('2026-08-24T12:00:31+00:00'),
            )?->document->stylesheet,
        );
        self::assertNull($repository->claim(
            $contextA,
            $request->requestId,
            $transport,
            new DateTimeImmutable('2026-08-24T12:00:32+00:00'),
        ));
        self::assertSame('20260824040000_studio_preview_grants', $migration->id());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $migration->checksum());
        $cancellations = $database->createSchemaManager()->introspectTable(
            $tables->raw('studio_preview_cancellations'),
        );
        foreach (['resource_context_key', 'draft_digest', 'cancel_port_sequence'] as $column) {
            self::assertTrue($cancellations->hasColumn($column));
        }
    }

    /**
     * Cancellation while a render is pending prevents its released late result from becoming claimable.
     *
     * @return  void
     *
     * @throws  JsonException  When a committed host vector is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testCancelledRenderVectorDiscardsTheLateCompletion(): void
    {
        [$repository] = self::runtime();
        $vector = self::vector('preview.render.cancelled.sequence.json');
        self::assertSame('vector.host-sequence.preview.render.cancelled', $vector->id);
        [$renderStep, $cancelStep] = self::steps($vector);
        $request = self::renderRequest(self::renderArgument($renderStep));
        $snapshot = self::snapshot(self::contextKey($renderStep), $request->artifactId);
        $transport = new StudioPreviewTransport(
            'https://kumwe.test',
            'channels/vector',
            'sources/vector',
            0,
        );
        self::claimPortSequences($repository, $snapshot->session->resourceContextKey, 1);

        self::assertSame(StudioPreviewRenderAdmission::Accepted, $repository->begin(
            $snapshot,
            $request,
            $transport,
            new DateTimeImmutable('2026-08-24T12:01:00+00:00'),
        ));
        $repository->cancel($snapshot->session->resourceContextKey, self::cancelDigest($cancelStep), 1);
        self::assertFalse($repository->complete(
            $snapshot->session->resourceContextKey,
            $request,
            new StudioPreviewRenderedDocument('<!doctype html><title>Late</title>', [], []),
        ));
        self::assertNull($repository->claim(
            $snapshot,
            $request->requestId,
            $transport,
            new DateTimeImmutable('2026-08-24T12:00:30+00:00'),
        ));
    }

    /**
     * A durable cancellation suppresses delayed older work but never a later same-digest render.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed preview vector is invalid.
     *
     * @since   2.0.0
     */
    public function testCancellationTombstoneFencesOnlyOlderPortSequences(): void
    {
        [$repository, , $database, $tables] = self::runtime();
        $vector = self::vector('preview.render.cancelled.sequence.json');
        [$renderStep] = self::steps($vector);
        $original = self::renderRequest(self::renderArgument($renderStep));
        $contextA = self::snapshot('contexts/cancel-before-begin', $original->artifactId);
        $old = self::request($original, 'renders/delayed-old');
        $new = self::request($original, 'renders/later-same-digest');
        $expiresAt = new DateTimeImmutable('2026-08-24T12:01:00+00:00');
        $transport = static fn (int $sequence): StudioPreviewTransport => new StudioPreviewTransport(
            'https://kumwe.test',
            'channels/vector',
            'sources/vector',
            $sequence,
        );
        $unfenced = self::snapshot('contexts/unfenced', $original->artifactId);
        self::assertFenceRefusal(fn () => $repository->begin($unfenced, $old, $transport(0), $expiresAt));
        self::assertFenceRefusal(
            fn () => $repository->cancel($unfenced->session->resourceContextKey, $old->draftDigest, 1),
        );
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE resource_context_key = ?',
            $tables->quoted('studio_preview_grants'),
        ), [$unfenced->session->resourceContextKey]));
        self::assertSame('0', (string) $database->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE resource_context_key = ?',
            $tables->quoted('studio_preview_cancellations'),
        ), [$unfenced->session->resourceContextKey]));
        self::claimPortSequences($repository, $contextA->session->resourceContextKey, 2);

        $repository->cancel($contextA->session->resourceContextKey, $old->draftDigest, 1);
        self::assertSame(
            StudioPreviewRenderAdmission::Cancelled,
            $repository->begin($contextA, $old, $transport(0), $expiresAt),
        );
        self::assertFalse($repository->complete(
            $contextA->session->resourceContextKey,
            $old,
            new StudioPreviewRenderedDocument('<!doctype html><title>Suppressed</title>', [], []),
        ));
        self::assertSame(
            StudioPreviewRenderAdmission::Accepted,
            $repository->begin($contextA, $new, $transport(2), $expiresAt),
        );
        $repository->cancel($contextA->session->resourceContextKey, $new->draftDigest, 1);
        self::assertTrue($repository->complete(
            $contextA->session->resourceContextKey,
            $new,
            new StudioPreviewRenderedDocument('<!doctype html><title>Later</title>', [], []),
        ));

        $contextB = self::snapshot('contexts/cancel-high-water', $original->artifactId);
        $delayed = self::request($original, 'renders/before-high-water');
        $future = self::request($original, 'renders/after-high-water');
        self::claimPortSequences($repository, $contextB->session->resourceContextKey, 4);
        $repository->cancel($contextB->session->resourceContextKey, $original->draftDigest, 3);
        $repository->cancel($contextB->session->resourceContextKey, $original->draftDigest, 1);
        self::assertSame(
            '3',
            (string) $database->fetchOne(sprintf(
                'SELECT cancel_port_sequence FROM %s WHERE resource_context_key = ? AND draft_digest = ?',
                $tables->quoted('studio_preview_cancellations'),
            ), [$contextB->session->resourceContextKey, $original->draftDigest]),
        );
        self::assertSame(
            StudioPreviewRenderAdmission::Cancelled,
            $repository->begin($contextB, $delayed, $transport(2), $expiresAt),
        );
        self::assertSame(
            StudioPreviewRenderAdmission::Accepted,
            $repository->begin($contextB, $future, $transport(4), $expiresAt),
        );
    }

    /**
     * Sequence claims are exact, monotonic and independent per delivery direction.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSequenceLedgerRefusesReplayAndOutOfOrderDeliveryOnBothEndpoints(): void
    {
        [$repository] = self::runtime();

        self::assertSame(
            StudioPreviewSequenceClaim::Accepted,
            $repository->advance('contexts/sequences', 'port', 0),
        );
        self::assertSame(
            StudioPreviewSequenceClaim::Refused,
            $repository->advance('contexts/sequences', 'port', 0),
        );
        self::assertSame(
            StudioPreviewSequenceClaim::PredecessorPending,
            $repository->advance('contexts/sequences', 'port', 2),
        );
        self::assertSame(
            StudioPreviewSequenceClaim::Accepted,
            $repository->advance('contexts/sequences', 'port', 1),
        );
        self::assertSame(
            StudioPreviewSequenceClaim::Accepted,
            $repository->advance('contexts/sequences', 'document', 0),
        );
        self::assertSame(
            StudioPreviewSequenceClaim::Refused,
            $repository->advance('contexts/sequences', 'document', 0),
        );
    }

    /**
     * A completed document cannot be claimed after its bounded expiry.
     *
     * @return  void
     *
     * @throws  JsonException  When the committed preview vector is invalid.
     *
     * @since   2.0.0
     */
    public function testExpiredGrantCannotBeClaimed(): void
    {
        [$repository] = self::runtime();
        $vector = self::vector('preview.render.cancelled.sequence.json');
        [$renderStep] = self::steps($vector);
        $original = self::renderRequest(self::renderArgument($renderStep));
        $request = new StudioPreviewRenderRequest(
            $original->artifactId,
            $original->draftDigest,
            $original->draftRevision,
            'renders/expired',
            $original->viewport,
        );
        $snapshot = self::snapshot('contexts/expired', $request->artifactId);
        $transport = new StudioPreviewTransport('https://kumwe.test', 'channels/vector', 'sources/vector', 0);
        self::claimPortSequences($repository, $snapshot->session->resourceContextKey, 0);

        self::assertSame(StudioPreviewRenderAdmission::Accepted, $repository->begin(
            $snapshot,
            $request,
            $transport,
            new DateTimeImmutable('2026-08-24T12:00:10+00:00'),
        ));
        self::assertTrue($repository->complete(
            $snapshot->session->resourceContextKey,
            $request,
            new StudioPreviewRenderedDocument('<!doctype html><title>Expired</title>', [], []),
        ));
        self::assertNull($repository->claim(
            $snapshot,
            $request->requestId,
            $transport,
            new DateTimeImmutable('2026-08-24T12:00:11+00:00'),
        ));
    }

    /**
     * Create the real SQLite schema and replay-safe Doctrine adapter.
     *
     * @return  array{DoctrineStudioPreviewRepository, StudioPreviewGrantMigration, Connection, TableNames}
     *          Test runtime.
     *
     * @since   2.0.0
     */
    private static function runtime(): array
    {
        $database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($database, 'kumwe_');
        $migration = new StudioPreviewGrantMigration($tables);
        $migration->up($database);
        $migration->up($database);

        return [new DoctrineStudioPreviewRepository($database, $tables), $migration, $database, $tables];
    }

    /**
     * Copy one canonical render request with a distinct session-unique attempt identifier.
     *
     * @param   StudioPreviewRenderRequest  $request    Canonical source request.
     * @param   string                      $requestId  Distinct render attempt identifier.
     *
     * @return  StudioPreviewRenderRequest  Request with unchanged draft identity.
     *
     * @since   2.0.0
     */
    private static function request(StudioPreviewRenderRequest $request, string $requestId): StudioPreviewRenderRequest
    {
        return new StudioPreviewRenderRequest(
            $request->artifactId,
            $request->draftDigest,
            $request->draftRevision,
            $requestId,
            $request->viewport,
        );
    }

    /**
     * Establish every accepted port sequence up to one operation whose execution may then be delayed.
     *
     * @param   DoctrineStudioPreviewRepository  $repository  Real portable preview ledger.
     * @param   string                           $contextKey  Opaque trusted host context.
     * @param   int                              $last        Last sequence to claim inclusively.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function claimPortSequences(
        DoctrineStudioPreviewRepository $repository,
        string $contextKey,
        int $last,
    ): void {
        for ($sequence = 0; $sequence <= $last; $sequence++) {
            self::assertSame(
                StudioPreviewSequenceClaim::Accepted,
                $repository->advance($contextKey, 'port', $sequence),
            );
        }
    }

    /**
     * Assert begin or cancellation rolls back when transport authorization did not establish its fence.
     *
     * @param   callable  $operation  Unfenced repository operation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertFenceRefusal(callable $operation): void
    {
        try {
            $operation();
            self::fail('An unfenced Studio preview operation reached persistence.');
        } catch (RuntimeException $exception) {
            self::assertSame('The Studio preview port sequence fence is unavailable.', $exception->getMessage());
        }
    }

    /**
     * Build one exact render request from an unmodified raw host-sequence argument.
     *
     * @param   stdClass  $argument  Raw vector argument object.
     *
     * @return  StudioPreviewRenderRequest  Exact vector render identity.
     *
     * @since   2.0.0
     */
    private static function renderRequest(stdClass $argument): StudioPreviewRenderRequest
    {
        return StudioPreviewRenderRequest::fromPayload($argument);
    }

    /**
     * Resolve the two exact ordered steps from a committed host-sequence vector.
     *
     * @param   stdClass  $vector  Decoded host-sequence vector.
     *
     * @return  array{stdClass, stdClass}  Render and cancellation steps.
     *
     * @throws  RuntimeException  When the vector step list is malformed.
     *
     * @since   2.0.0
     */
    private static function steps(stdClass $vector): array
    {
        $steps = $vector->steps ?? null;
        $render = is_array($steps) ? ($steps[0] ?? null) : null;
        $cancel = is_array($steps) ? ($steps[1] ?? null) : null;
        if (!$render instanceof stdClass || !$cancel instanceof stdClass) {
            throw new RuntimeException('The Studio preview host-sequence steps are invalid.');
        }

        return [$render, $cancel];
    }

    /**
     * Resolve one exact raw render payload from a host-sequence step.
     *
     * @param   stdClass  $step  Render step.
     *
     * @return  stdClass  Raw render payload.
     *
     * @throws  RuntimeException  When the render argument is malformed.
     *
     * @since   2.0.0
     */
    private static function renderArgument(stdClass $step): stdClass
    {
        $argument = $step->argument ?? null;
        if (!$argument instanceof stdClass) {
            throw new RuntimeException('The Studio preview render argument is invalid.');
        }

        return $argument;
    }

    /**
     * Resolve one exact cancellation digest from a host-sequence step.
     *
     * @param   stdClass  $step  Cancellation step.
     *
     * @return  string  Exact draft digest.
     *
     * @throws  RuntimeException  When the cancellation argument is malformed.
     *
     * @since   2.0.0
     */
    private static function cancelDigest(stdClass $step): string
    {
        $digest = $step->argument ?? null;
        if (!is_string($digest)) {
            throw new RuntimeException('The Studio preview cancellation digest is invalid.');
        }

        return $digest;
    }

    /**
     * Resolve the opaque context identity from one host-sequence step.
     *
     * @param   stdClass  $step  Host-sequence step.
     *
     * @return  string  Opaque resource-context identity.
     *
     * @throws  RuntimeException  When the context coordinate is malformed.
     *
     * @since   2.0.0
     */
    private static function contextKey(stdClass $step): string
    {
        $context = $step->context ?? null;
        $key = $context instanceof stdClass ? $context->resourceContextKey ?? null : null;
        if (!is_string($key)) {
            throw new RuntimeException('The Studio preview host context is invalid.');
        }

        return $key;
    }

    /**
     * Build a trusted snapshot with the resource-context identity named by a host vector.
     *
     * @param   string  $contextKey  Opaque resource-context identity.
     * @param   string  $artifactId  Exact Blueprint artifact identity.
     *
     * @return  StudioHostSessionSnapshot  Read-authorized preview session.
     *
     * @since   2.0.0
     */
    private static function snapshot(string $contextKey, string $artifactId): StudioHostSessionSnapshot
    {
        $session = new StudioHostSession(
            $contextKey,
            'actor-vector',
            'default',
            null,
            null,
            'administrator',
            hash('sha256', 'vector-session'),
            StudioSessionMode::Blueprint,
            StudioResourceKind::Blueprint,
            $artifactId,
            'session-r1',
        );

        return new StudioHostSessionSnapshot($session, ['studio.permission/read'], 'session-r1', true, false, false);
    }

    /**
     * Decode one exact committed host-sequence vector.
     *
     * @param   string  $filename  Committed vector filename.
     *
     * @return  stdClass  Decoded vector object.
     *
     * @throws  JsonException  When the fixture is invalid.
     * @throws  RuntimeException  When it does not decode to an object.
     *
     * @since   2.0.0
     */
    private static function vector(string $filename): stdClass
    {
        $vector = json_decode(
            StudioContractResources::testkitBytes('vectors/host-sequence/' . $filename),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        if (!$vector instanceof stdClass) {
            throw new RuntimeException('The Studio preview host-sequence vector is invalid.');
        }

        return $vector;
    }
}
