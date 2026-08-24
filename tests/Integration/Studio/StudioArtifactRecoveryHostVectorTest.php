<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Studio;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Audit\Application\AuditRecorder;
use Kumwe\App\Audit\Domain\AuditEvent;
use Kumwe\App\Infrastructure\Persistence\DoctrineTransactionManager;
use Kumwe\App\Infrastructure\Persistence\Migration\StudioArtifactRecoveryMigration;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioArtifactHostPort;
use Kumwe\App\Studio\Application\Host\StudioArtifactPublicationGuard;
use Kumwe\App\Studio\Application\Host\StudioHostOperationRefused;
use Kumwe\App\Studio\Application\Host\StudioHostResult;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Application\Host\StudioMutationExecutor;
use Kumwe\App\Studio\Application\Host\StudioRecoveryHostPort;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Kumwe\App\Studio\Infrastructure\Persistence\DoctrineStudioHostStorage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use stdClass;

/**
 * Replays every vendored artifact/recovery host vector and every applicable host-sequence vector.
 *
 * Expectations are read from the pinned JSON documents. The harness only translates their semantic
 * `argument` into the actual published HTTP wrapper, exactly as `createHttpHostAdapter` does.
 *
 * @since  2.0.0
 */
#[CoversClass(DoctrineStudioHostStorage::class)]
#[CoversClass(StudioArtifactAdmission::class)]
#[CoversClass(StudioArtifactHostPort::class)]
#[CoversClass(StudioHostOperationRefused::class)]
#[CoversClass(StudioHostResult::class)]
#[CoversClass(StudioMutationExecutor::class)]
#[CoversClass(StudioRecoveryHostPort::class)]
final class StudioArtifactRecoveryHostVectorTest extends TestCase
{
    /**
     * Supply every single-operation artifact/recovery vector directly from the vendored corpus.
     *
     * @return  iterable<string, array{stdClass}>  Vector ID to decoded vector.
     *
     * @since   2.0.0
     */
    public static function hostVectors(): iterable
    {
        $files = array_merge(
            glob(self::vectorDirectory() . '/host/artifact.*.json') ?: [],
            glob(self::vectorDirectory() . '/host/recovery.*.json') ?: [],
        );
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $vector = self::decode($file);
            yield $vector->id => [$vector];
        }
    }

    /**
     * Replay one published host vector through the production AP-4 application port and DBAL adapter.
     *
     * @param   stdClass  $vector  Decoded vendored single-operation vector.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('hostVectors')]
    public function testEveryArtifactAndRecoveryHostVectorReplays(stdClass $vector): void
    {
        $runtime = new StudioArtifactRecoveryVectorRuntime($vector);

        try {
            $result = $runtime->invoke(
                $vector->port,
                $vector->operation,
                $vector->context,
                $vector->argument ?? null,
            );
            self::assertSame('result', $vector->expect->outcome, $vector->id);
            $this->assertResultExpectation($vector->expect, $result);
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('error', $vector->expect->outcome, $vector->id);
            self::assertSame($vector->expect->category, $refused->category, $vector->id);
            if (property_exists($vector->expect, 'revision')) {
                self::assertSame($vector->expect->revision, $refused->revision, $vector->id);
            }
            foreach ($vector->expect->messageMustNotContain ?? [] as $secret) {
                self::assertStringNotContainsString($secret, $refused->getMessage(), $vector->id);
            }
        }
    }

    /**
     * Supply every relevant multi-attempt artifact/recovery sequence from the pinned corpus.
     *
     * @return  iterable<string, array{stdClass}>  Vector ID to decoded sequence.
     *
     * @since   2.0.0
     */
    public static function hostSequenceVectors(): iterable
    {
        $files = array_merge(
            glob(self::vectorDirectory() . '/host-sequence/artifact.*.sequence.json') ?: [],
            glob(self::vectorDirectory() . '/host-sequence/recovery.*.sequence.json') ?: [],
        );
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $vector = self::decode($file);
            yield $vector->id => [$vector];
        }
    }

    /**
     * Replay changed intent, completed/in-flight equivalence, numeric normalization, context scope and rate reset.
     *
     * @param   stdClass  $vector  Decoded vendored host-sequence vector.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('hostSequenceVectors')]
    public function testEveryArtifactAndRecoverySequenceReplays(stdClass $vector): void
    {
        $runtime = new StudioArtifactRecoveryVectorRuntime($vector);
        /** @var array<string, StudioHostResult|StudioHostOperationRefused> $settled */
        $settled = [];
        /** @var array<string, stdClass> $pending */
        $pending = [];

        foreach ($vector->steps as $step) {
            if ($step->action === 'advance-clock') {
                $runtime->clock->advance($step->milliseconds);
                continue;
            }
            if ($step->action === 'settle') {
                $source = $settled[$step->invocation] ?? $settled['publish-coalesced'] ?? null;
                self::assertInstanceOf(StudioHostResult::class, $source, $vector->id . ':' . $step->id);
                $settled[$step->id] = $source;
                continue;
            }
            self::assertSame('invoke', $step->action);
            if (($step->completion ?? 'settled') === 'pending') {
                $pending[$step->id] = $step;
                continue;
            }
            try {
                $outcome = $runtime->invoke($step->port, $step->operation, $step->context, $step->argument);
                $settled[$step->id] = $outcome;
                self::assertSame('result', $step->expect->outcome, $vector->id . ':' . $step->id);
                $this->assertResultExpectation($step->expect, $outcome);
                foreach ($pending as $pendingId => $pendingStep) {
                    if (
                        $pendingStep->port === $step->port
                        && $pendingStep->operation === $step->operation
                        && ($pendingStep->context->idempotencyKey ?? null)
                            === ($step->context->idempotencyKey ?? null)
                    ) {
                        $replay = $runtime->invoke(
                            $pendingStep->port,
                            $pendingStep->operation,
                            $pendingStep->context,
                            $pendingStep->argument,
                        );
                        self::assertSame($outcome->revision, $replay->revision, $vector->id);
                        self::assertEquals($outcome->value, $replay->value, $vector->id);
                        $settled[$pendingId] = $replay;
                        unset($pending[$pendingId]);
                    }
                }
            } catch (StudioHostOperationRefused $refused) {
                $settled[$step->id] = $refused;
                self::assertSame('error', $step->expect->outcome, $vector->id . ':' . $step->id);
                self::assertSame($step->expect->category, $refused->category, $vector->id . ':' . $step->id);
                if (property_exists($step->expect, 'retryable')) {
                    self::assertSame($step->expect->retryable, $refused->retryable, $vector->id);
                }
                if (property_exists($step->expect, 'retryAfterMilliseconds')) {
                    self::assertSame(
                        $step->expect->retryAfterMilliseconds,
                        $refused->retryAfterMilliseconds,
                        $vector->id,
                    );
                }
            }
        }

        self::assertSame([], $pending, $vector->id . ' must settle every in-flight invocation.');
    }

    /**
     * Successful mutations audit once with redacted metadata, while a recorder failure rolls storage back.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMutationsAuditAtomicallyWithoutPersistingHostPayloadsOrSecrets(): void
    {
        $vector = self::decode(self::vectorDirectory() . '/host/artifact.save.accepted.json');
        $runtime = new StudioArtifactRecoveryVectorRuntime($vector);
        $runtime->invoke($vector->port, $vector->operation, $vector->context, $vector->argument);
        $runtime->invoke($vector->port, $vector->operation, $vector->context, $vector->argument);

        self::assertInstanceOf(StudioArtifactRecoveryRecordingAudit::class, $runtime->audit);
        self::assertCount(1, $runtime->audit->events);
        $event = $runtime->audit->events[0];
        self::assertSame('studio.artifact.save', $event->action());
        self::assertSame('studio_artifact', $event->subjectType());
        self::assertSame('success', $event->outcome());
        self::assertSame('publisher-vector', $event->metadata()['site_identifier']);
        $auditBytes = $event->metadataAsJson();
        foreach (['contexts/vector', 'idempotency/save-1', 'vector.blueprint', 'dependencyLock', 'slots'] as $secret) {
            self::assertStringNotContainsString($secret, $auditBytes);
        }

        $failing = new class implements AuditRecorder {
            /**
             * Refuse the audit write to prove the surrounding mutation rolls back.
             *
             * @param   AuditEvent  $event  Event whose write is refused.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function record(AuditEvent $event): void
            {
                throw new RuntimeException('audit unavailable');
            }
        };
        $rollback = new StudioArtifactRecoveryVectorRuntime($vector, $failing);
        try {
            $rollback->invoke($vector->port, $vector->operation, $vector->context, $vector->argument);
            self::fail('A failed Studio audit write must abort the artifact mutation.');
        } catch (RuntimeException $failure) {
            self::assertSame('audit unavailable', $failure->getMessage());
        }
        self::assertSame('vector.blueprint-r1', $rollback->current('vector.blueprint', '1.0.0')?->revision);
    }

    /**
     * Prove save permission cannot perform lifecycle transitions or edit a published head.
     *
     * Canonical unpublish remains the only path that returns a published artifact to a saveable draft.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveCannotBypassLifecycleOperationsAndRequiresCanonicalUnpublish(): void
    {
        $vector = self::decode(self::vectorDirectory() . '/host/artifact.save.accepted.json');
        $vector->given->permissions = ['studio.permission/save'];
        $runtime = new StudioArtifactRecoveryVectorRuntime($vector);
        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        $candidate->status = 'published';

        try {
            $runtime->saveDocument($vector->context, $candidate);
            self::fail('Artifact save must never turn a draft into a published revision.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('conflict', $refused->category);
            self::assertSame('studio.artifact/lifecycle-change-requires-publish', $refused->diagnosticCode);
            self::assertSame('vector.blueprint-r1', $refused->revision);
        }
        self::assertSame('draft', $runtime->current('vector.blueprint', '1.0.0')?->status);
        self::assertSame('vector.blueprint-r1', $runtime->current('vector.blueprint', '1.0.0')?->revision);

        $vector = self::decode(self::vectorDirectory() . '/host/artifact.save.accepted.json');
        $vector->given->artifacts[0]->status = 'published';
        $runtime = new StudioArtifactRecoveryVectorRuntime($vector);
        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);

        try {
            $runtime->saveDocument($vector->context, $candidate);
            self::fail('A published artifact must be canonically unpublished before save.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('conflict', $refused->category);
            self::assertSame('studio.artifact/not-draft', $refused->diagnosticCode);
            self::assertSame('vector.blueprint-r1', $refused->revision);
        }

        $unpublish = clone $vector->context;
        $unpublish->operationId = 'studio.operation/artifact.unpublish';
        $unpublish->requestId = 'requests/unpublish-before-save';
        $unpublish->idempotencyKey = 'idempotency/unpublish-before-save';
        $unpublished = $runtime->invoke(
            'artifact',
            'unpublish',
            $unpublish,
            (object) ['id' => 'vector.blueprint', 'version' => '1.0.0'],
        );
        self::assertNotNull($unpublished->revision);

        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        $candidate->label->defaultMessage = 'Saved only after canonical unpublish';
        $save = clone $vector->context;
        $save->requestId = 'requests/save-after-unpublish';
        $save->expectedRevision = $unpublished->revision;
        $save->idempotencyKey = 'idempotency/save-after-unpublish';
        $saved = $runtime->saveDocument($save, $candidate);

        self::assertNotNull($saved->revision);
        self::assertNotSame($unpublished->revision, $saved->revision);
        self::assertSame('draft', $runtime->current('vector.blueprint', '1.0.0')?->status);
    }

    /**
     * Publish and unpublish consume only their exact target-specific live authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLifecycleMutationsCannotBorrowTheOppositeTargetAuthority(): void
    {
        $file = self::vectorDirectory() . '/host/artifact.publish.accepted.json';
        $publishVector = self::decode($file);
        $publishOnly = new StudioArtifactRecoveryVectorRuntime(
            $publishVector,
            canPublish: true,
            canUnpublish: false,
        );

        $published = $publishOnly->invoke(
            $publishVector->port,
            $publishVector->operation,
            $publishVector->context,
            $publishVector->argument,
        );
        self::assertNotNull($published->revision);
        $publishedHead = $publishOnly->current('vector.blueprint', '1.0.0');
        self::assertInstanceOf(StoredStudioArtifact::class, $publishedHead);
        self::assertSame('published', $publishedHead->status);

        $unpublish = clone $publishVector->context;
        $unpublish->operationId = 'studio.operation/artifact.unpublish';
        $unpublish->requestId = 'requests/unpublish-with-publish-only';
        $unpublish->idempotencyKey = 'idempotency/unpublish-with-publish-only';
        $unpublish->expectedRevision = $published->revision;
        try {
            $publishOnly->invoke('artifact', 'unpublish', $unpublish, $publishVector->argument);
            self::fail('Publication authority must not authorize return to draft.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('forbidden', $refused->category);
            self::assertSame('studio.host/action-forbidden', $refused->diagnosticCode);
        }
        $afterRefusedUnpublish = $publishOnly->current('vector.blueprint', '1.0.0');
        self::assertInstanceOf(StoredStudioArtifact::class, $afterRefusedUnpublish);
        self::assertSame($publishedHead->revision, $afterRefusedUnpublish->revision);
        self::assertSame($publishedHead->status, $afterRefusedUnpublish->status);
        self::assertInstanceOf(StudioArtifactRecoveryRecordingAudit::class, $publishOnly->audit);
        self::assertCount(1, $publishOnly->audit->events);

        $unpublishVector = self::decode($file);
        $unpublishVector->given->artifacts[0]->status = 'published';
        $unpublishOnly = new StudioArtifactRecoveryVectorRuntime(
            $unpublishVector,
            canPublish: false,
            canUnpublish: true,
        );
        $unpublish = clone $unpublishVector->context;
        $unpublish->operationId = 'studio.operation/artifact.unpublish';
        $unpublish->requestId = 'requests/unpublish-with-unpublish-only';
        $unpublish->idempotencyKey = 'idempotency/unpublish-with-unpublish-only';
        $unpublished = $unpublishOnly->invoke('artifact', 'unpublish', $unpublish, $unpublishVector->argument);
        self::assertNotNull($unpublished->revision);
        $draftHead = $unpublishOnly->current('vector.blueprint', '1.0.0');
        self::assertInstanceOf(StoredStudioArtifact::class, $draftHead);
        self::assertSame('draft', $draftHead->status);

        $publish = clone $unpublishVector->context;
        $publish->requestId = 'requests/publish-with-unpublish-only';
        $publish->idempotencyKey = 'idempotency/publish-with-unpublish-only';
        $publish->expectedRevision = $unpublished->revision;
        try {
            $unpublishOnly->invoke('artifact', 'publish', $publish, $unpublishVector->argument);
            self::fail('Return-to-draft authority must not authorize publication.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('forbidden', $refused->category);
            self::assertSame('studio.host/action-forbidden', $refused->diagnosticCode);
        }
        $afterRefusedPublish = $unpublishOnly->current('vector.blueprint', '1.0.0');
        self::assertInstanceOf(StoredStudioArtifact::class, $afterRefusedPublish);
        self::assertSame($draftHead->revision, $afterRefusedPublish->revision);
        self::assertSame($draftHead->status, $afterRefusedPublish->status);
        self::assertInstanceOf(StudioArtifactRecoveryRecordingAudit::class, $unpublishOnly->audit);
        self::assertCount(1, $unpublishOnly->audit->events);
    }

    /**
     * Prove App-owned publication crosses the live runtime guard and rolls back every refusal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAppOwnedBlueprintPublicationRequiresExactLiveRuntimeCompatibility(): void
    {
        $file = self::vectorDirectory() . '/host/artifact.publish.accepted.json';
        $accepted = self::decode($file);
        $guard = new StudioArtifactPublicationGuardProbe();
        $runtime = new StudioArtifactRecoveryVectorRuntime(
            $accepted,
            publication: $guard,
            appOwnedBlueprint: true,
        );

        $result = $runtime->invoke(
            $accepted->port,
            $accepted->operation,
            $accepted->context,
            $accepted->argument,
        );

        self::assertNotNull($result->revision);
        self::assertSame(1, $guard->calls);
        self::assertSame('published', $runtime->current('vector.blueprint', '1.0.0')?->status);

        $refusals = [
            'studio.artifact/blueprint-incompatible',
            'studio.artifact/model-lock-mismatch',
            'studio.artifact/theme-lock-mismatch',
            'studio.artifact/block-renderer-unavailable',
        ];
        foreach ($refusals as $diagnosticCode) {
            $vector = self::decode($file);
            $guard = new StudioArtifactPublicationGuardProbe($diagnosticCode);
            $runtime = new StudioArtifactRecoveryVectorRuntime(
                $vector,
                publication: $guard,
                appOwnedBlueprint: true,
            );
            $before = $runtime->current('vector.blueprint', '1.0.0');
            self::assertInstanceOf(StoredStudioArtifact::class, $before);

            try {
                $runtime->invoke($vector->port, $vector->operation, $vector->context, $vector->argument);
                self::fail(sprintf('Publication must refuse %s.', $diagnosticCode));
            } catch (StudioHostOperationRefused $refused) {
                self::assertSame('conflict', $refused->category, $diagnosticCode);
                self::assertSame($diagnosticCode, $refused->diagnosticCode, $diagnosticCode);
                self::assertSame($before->revision, $refused->revision, $diagnosticCode);
            }

            $after = $runtime->current('vector.blueprint', '1.0.0');
            self::assertInstanceOf(StoredStudioArtifact::class, $after);
            self::assertSame(1, $guard->calls, $diagnosticCode);
            self::assertSame($before->revision, $after->revision, $diagnosticCode);
            self::assertSame($before->status, $after->status, $diagnosticCode);
            self::assertSame($before->canonicalDocument, $after->canonicalDocument, $diagnosticCode);
        }
    }

    /**
     * Prove a Blueprint save cannot drift its owner, model or dependency locks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testBlueprintSaveRejectsEveryImmutableLockDrift(): void
    {
        foreach (['owner', 'model', 'dependency-lock'] as $lock) {
            $vector = self::decode(self::vectorDirectory() . '/host/artifact.save.accepted.json');
            $runtime = new StudioArtifactRecoveryVectorRuntime($vector);
            $before = $runtime->current('vector.blueprint', '1.0.0');
            self::assertInstanceOf(StoredStudioArtifact::class, $before);
            $candidate = $before->document();
            if ($lock === 'owner') {
                $candidate->owner->version = '2.0.0';
            } elseif ($lock === 'model') {
                $candidate->model->revision = 'product-model-r2';
            } else {
                $candidate->dependencyLock->theme->revision = 'commerce-theme-r4';
            }

            try {
                $runtime->saveDocument($vector->context, $candidate);
                self::fail(sprintf('Blueprint %s drift must be refused.', $lock));
            } catch (StudioHostOperationRefused $refused) {
                self::assertSame('conflict', $refused->category, $lock);
                self::assertSame('studio.artifact/blueprint-lock-conflict', $refused->diagnosticCode, $lock);
                self::assertSame('vector.blueprint-r1', $refused->revision, $lock);
            }

            $after = $runtime->current('vector.blueprint', '1.0.0');
            self::assertInstanceOf(StoredStudioArtifact::class, $after);
            self::assertSame($before->canonicalDocument, $after->canonicalDocument, $lock);
            self::assertSame($before->canonicalDependencies, $after->canonicalDependencies, $lock);
        }
    }

    /**
     * Prove a bound save cannot mint another artifact version or cross its trusted resource identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSaveCannotEscapeItsExistingResourceCoordinate(): void
    {
        $vector = self::decode(self::vectorDirectory() . '/host/artifact.save.accepted.json');
        $runtime = new StudioArtifactRecoveryVectorRuntime($vector);
        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        $candidate->version = '2.0.0';

        try {
            $runtime->saveDocument($vector->context, $candidate);
            self::fail('Artifact save must not create a new version coordinate.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('not-found', $refused->category);
            self::assertSame('studio.artifact/not-found', $refused->diagnosticCode);
        }
        self::assertNull($runtime->current('vector.blueprint', '2.0.0'));
        self::assertSame('vector.blueprint-r1', $runtime->current('vector.blueprint', '1.0.0')?->revision);

        $candidate = $runtime->current('vector.blueprint', '1.0.0')?->document();
        self::assertInstanceOf(stdClass::class, $candidate);
        $candidate->id = 'vector.other-blueprint';
        try {
            $runtime->saveDocument($vector->context, $candidate, 'vector.blueprint');
            self::fail('Artifact save must not cross its trusted resource identifier.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('not-found', $refused->category);
            self::assertSame('studio.artifact/not-found', $refused->diagnosticCode);
        }
        self::assertNull($runtime->current('vector.other-blueprint', '1.0.0'));
        self::assertSame('vector.blueprint-r1', $runtime->current('vector.blueprint', '1.0.0')?->revision);
    }

    /**
     * Assert only expectation fields the corpus declares, without duplicating their values in PHP.
     *
     * @param   stdClass          $expect  Vendored expected outcome.
     * @param   StudioHostResult  $result  Actual production-port result.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function assertResultExpectation(stdClass $expect, StudioHostResult $result): void
    {
        if (($expect->value ?? null) === 'null') {
            self::assertNull($result->value);
        } elseif (($expect->value ?? null) === 'artifact') {
            self::assertInstanceOf(stdClass::class, $result->value);
        } elseif (($expect->value ?? null) === 'artifact-references') {
            self::assertIsArray($result->value);
        }
        if (property_exists($expect, 'revision')) {
            self::assertSame($expect->revision, $result->revision);
        }
        if (($expect->revisionAdvances ?? false) === true) {
            self::assertNotNull($result->revision);
        }
        if (property_exists($expect, 'revisionAdvancesFrom')) {
            self::assertNotSame($expect->revisionAdvancesFrom, $result->revision);
        }
    }

    /**
     * Locate the exact vendored Studio testkit vector directory.
     *
     * @return  string  Absolute vector-directory path.
     *
     * @since   2.0.0
     */
    private static function vectorDirectory(): string
    {
        return dirname(__DIR__, 2) . '/Fixtures/Studio/testkit/vectors';
    }

    /**
     * Decode one required vendored vector as a JSON object.
     *
     * @param   string  $file  Absolute vector path.
     *
     * @return  stdClass  Decoded vector document.
     *
     * @since   2.0.0
     */
    private static function decode(string $file): stdClass
    {
        $document = json_decode((string) file_get_contents($file), false, 64, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }
}

/**
 * Real-storage vector harness translating only the published HTTP wrapper layer.
 *
 * @since  2.0.0
 */
final class StudioArtifactRecoveryVectorRuntime
{
    /**
     * Mutable clock exposed to sequence `advance-clock` steps.
     *
     * @var    StudioArtifactRecoveryMutableClock
     * @since  2.0.0
     */
    public readonly StudioArtifactRecoveryMutableClock $clock;

    /**
     * Audit sink used by the production mutation executor.
     *
     * @var    AuditRecorder
     * @since  2.0.0
     */
    public readonly AuditRecorder $audit;

    /**
     * Isolated migrated vector database.
     *
     * @var    Connection
     * @since  2.0.0
     */
    private readonly Connection $database;

    /**
     * Transaction boundary used by production persistence.
     *
     * @var    DoctrineTransactionManager
     * @since  2.0.0
     */
    private readonly DoctrineTransactionManager $transactions;

    /**
     * Production DBAL persistence adapter under test.
     *
     * @var    DoctrineStudioHostStorage
     * @since  2.0.0
     */
    private readonly DoctrineStudioHostStorage $storage;

    /**
     * Production artifact admission boundary under test.
     *
     * @var    StudioArtifactAdmission
     * @since  2.0.0
     */
    private readonly StudioArtifactAdmission $admission;

    /**
     * Production artifact host port under test.
     *
     * @var    StudioArtifactHostPort
     * @since  2.0.0
     */
    private readonly StudioArtifactHostPort $artifact;

    /**
     * Production recovery host port under test.
     *
     * @var    StudioRecoveryHostPort
     * @since  2.0.0
     */
    private readonly StudioRecoveryHostPort $recovery;

    /**
     * Sorted effective permissions supplied by the vector's given state.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private readonly array $permissions;

    /**
     * Exact publication authority supplied by the trusted host snapshot.
     *
     * @var    bool
     * @since  2.0.0
     */
    private readonly bool $canPublish;

    /**
     * Exact return-to-draft authority supplied by the trusted host snapshot.
     *
     * @var    bool
     * @since  2.0.0
     */
    private readonly bool $canUnpublish;

    /**
     * Session generation supplied by the vector's given state.
     *
     * @var    string
     * @since  2.0.0
     */
    private readonly string $generation;

    /**
     * Whether seeded Blueprint fixtures use the App Content owner coordinate.
     *
     * @var    bool
     * @since  2.0.0
     */
    private readonly bool $appOwnedBlueprint;

    /**
     * Build an isolated migrated runtime from one vector's `given` state.
     *
     * @param  stdClass                             $vector             Decoded vendored vector or sequence.
     * @param  AuditRecorder|null                   $audit              Optional failing or observing audit sink.
     * @param  StudioArtifactPublicationGuard|null  $publication        Optional exact publication guard probe.
     * @param  bool                                 $appOwnedBlueprint  Whether Blueprint seeds are App-owned.
     * @param  bool|null                            $canPublish         Optional exact publication authority override.
     * @param  bool|null                            $canUnpublish       Optional exact unpublication authority override.
     *
     * @since  2.0.0
     */
    public function __construct(
        stdClass $vector,
        ?AuditRecorder $audit = null,
        ?StudioArtifactPublicationGuard $publication = null,
        bool $appOwnedBlueprint = false,
        ?bool $canPublish = null,
        ?bool $canUnpublish = null,
    ) {
        $this->database = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $tables = new TableNames($this->database, 'kumwe_');
        (new StudioArtifactRecoveryMigration($tables))->up($this->database);
        $this->transactions = new DoctrineTransactionManager($this->database);
        $this->storage = new DoctrineStudioHostStorage($this->database, $tables);
        $this->admission = new StudioArtifactAdmission(StudioContractSchemas::fromVendoredCorpus());
        $this->clock = new StudioArtifactRecoveryMutableClock();
        $this->audit = $audit ?? new StudioArtifactRecoveryRecordingAudit();
        $mutations = new StudioMutationExecutor($this->transactions, $this->storage, $this->audit, $this->clock);
        $this->artifact = new StudioArtifactHostPort(
            $this->storage,
            $this->admission,
            $mutations,
            $publication ?? new StudioArtifactPublicationGuardProbe(),
        );
        $rate = $vector->given->rateLimits[0] ?? null;
        $this->recovery = new StudioRecoveryHostPort(
            $this->storage,
            $mutations,
            $this->clock,
            262144,
            $rate instanceof stdClass ? $rate->maximumRequests : 60,
            $rate instanceof stdClass ? $rate->windowMilliseconds : 60000,
        );
        $permissions = $vector->given->permissions ?? [];
        sort($permissions, SORT_STRING);
        $this->permissions = $permissions;
        $protocolLifecycleAuthority = in_array('studio.permission/publish', $permissions, true);
        $this->canPublish = $canPublish ?? $protocolLifecycleAuthority;
        $this->canUnpublish = $canUnpublish ?? $protocolLifecycleAuthority;
        $this->generation = $vector->given->sessionGeneration;
        $this->appOwnedBlueprint = $appOwnedBlueprint;
        foreach ($vector->given->artifacts ?? [] as $seed) {
            $this->seed($seed);
        }
    }

    /**
     * Read the current stored artifact for atomic-audit assertions.
     *
     * @param   string  $id       Canonical artifact identifier.
     * @param   string  $version  Canonical artifact version.
     *
     * @return  StoredStudioArtifact|null  Current artifact or null.
     *
     * @since   2.0.0
     */
    public function current(string $id, string $version): ?StoredStudioArtifact
    {
        return $this->storage->current('publisher-vector', $id, $version);
    }

    /**
     * Invoke one semantic vector step through its production port and actual HTTP wrapper.
     *
     * @param   string    $port       Canonical port name.
     * @param   string    $operation  Canonical operation name.
     * @param   stdClass  $context    Vendored request context.
     * @param   mixed     $argument   Vendored semantic argument.
     *
     * @return  StudioHostResult  Production port result.
     *
     * @since   2.0.0
     */
    public function invoke(
        string $port,
        string $operation,
        stdClass $context,
        mixed $argument,
    ): StudioHostResult {
        $resourceId = $argument instanceof stdClass && property_exists($argument, 'id')
            ? $argument->id
            : 'recovery-resource';
        $kind = $port === 'artifact' ? $this->kind($resourceId, $argument) : 'blueprint';
        $arguments = $this->wrapper($port, $operation, $argument, $context);

        return $this->invokeWrapped($port, $operation, $context, $arguments, $resourceId, $kind);
    }

    /**
     * Invoke an exact admitted save document against a trusted resource binding.
     *
     * @param   stdClass    $context          Vendored request context.
     * @param   stdClass    $document         Exact candidate Studio document.
     * @param   string|null $boundResourceId  Optional adversarial trusted resource binding.
     *
     * @return  StudioHostResult  Production artifact-port result.
     *
     * @since   2.0.0
     */
    public function saveDocument(
        stdClass $context,
        stdClass $document,
        ?string $boundResourceId = null,
    ): StudioHostResult {
        $resourceId = $boundResourceId ?? ($document->id ?? null);
        if (!is_string($resourceId) || !is_string($document->kind ?? null)) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }

        return $this->invokeWrapped(
            'artifact',
            'save',
            $context,
            (object) ['document' => $document],
            $resourceId,
            $document->kind,
        );
    }

    /**
     * Dispatch one already-wrapped request through the production port and trusted session snapshot.
     *
     * @param   string    $port        Canonical port name.
     * @param   string    $operation   Canonical operation name.
     * @param   stdClass  $context     Vendored request context.
     * @param   stdClass  $arguments   Exact published HTTP wrapper.
     * @param   mixed     $resourceId  Trusted resource identifier candidate.
     * @param   string    $kind        Trusted resource artifact kind.
     *
     * @return  StudioHostResult  Production port result.
     *
     * @since   2.0.0
     */
    private function invokeWrapped(
        string $port,
        string $operation,
        stdClass $context,
        stdClass $arguments,
        mixed $resourceId,
        string $kind,
    ): StudioHostResult {
        $expectedOperation = 'studio.operation/' . $port . '.' . $operation;
        if (!hash_equals($expectedOperation, $context->operationId)) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/operation-mismatch');
        }
        if (!is_string($resourceId)) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $mode = match ($kind) {
            'content-model' => StudioSessionMode::Model,
            'entry' => StudioSessionMode::Content,
            default => StudioSessionMode::Blueprint,
        };
        $resourceKind = $kind === 'blueprint' ? StudioResourceKind::Blueprint : StudioResourceKind::Content;
        $session = new StudioHostSession(
            $context->resourceContextKey,
            'actor-vector',
            'publisher-vector',
            null,
            null,
            'administrator',
            str_repeat('a', 64),
            $mode,
            $resourceKind,
            $resourceId,
            $this->generation,
        );
        $snapshot = new StudioHostSessionSnapshot(
            $session,
            $this->permissions,
            $this->generation,
            true,
            $this->canPublish,
            $this->canUnpublish,
        );
        $request = new StudioHostRequest(
            $context->operationId,
            $context->protocolVersion,
            $context->requestId,
            $context->resourceContextKey,
            $context->sessionGeneration,
            $arguments,
            property_exists($context, 'expectedRevision') ? $context->expectedRevision : null,
            property_exists($context, 'idempotencyKey') ? $context->idempotencyKey : null,
            property_exists($context, 'locale') ? $context->locale : null,
            property_exists($context, 'traceContext') ? $context->traceContext : null,
        );

        return $port === 'artifact'
            ? $this->artifact->dispatch($operation, $request, $snapshot)
            : $this->recovery->dispatch($operation, $request, $snapshot);
    }

    /**
     * Seed one artifact declared by a vector's given state.
     *
     * @param   stdClass  $seed  Vendored artifact seed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function seed(stdClass $seed): void
    {
        $document = $this->fixture($seed->kind);
        $document->id = $seed->id;
        $document->revision = $seed->revision;
        $document->status = $seed->status ?? 'draft';
        if ($seed->kind === 'blueprint' && $this->appOwnedBlueprint) {
            $document->owner = (object) ['id' => 'kumwe.app/content', 'version' => '2.0.0'];
        }
        if ($seed->kind !== 'entry' && property_exists($seed, 'version')) {
            $document->version = $seed->version;
        }
        $artifact = $this->admission->admit('publisher-vector', $document);
        $this->transactions->transactional(fn (): bool => $this->storage->store($artifact, null));
    }

    /**
     * Translate semantic vector arguments to the actual `createHttpHostAdapter` request shape.
     *
     * @param   string    $port       Canonical port name.
     * @param   string    $operation  Canonical operation name.
     * @param   mixed     $argument   Vendored semantic argument.
     * @param   stdClass  $context    Vendored request context.
     *
     * @return  stdClass  Exact published HTTP wrapper.
     *
     * @since   2.0.0
     */
    private function wrapper(string $port, string $operation, mixed $argument, stdClass $context): stdClass
    {
        if ($port === 'recovery') {
            return $operation === 'store' ? (object) ['envelope' => $argument] : new stdClass();
        }
        if ($operation !== 'save') {
            return (object) ['reference' => $argument];
        }
        if (!$argument instanceof stdClass || !is_string($argument->kind ?? null)) {
            return (object) ['document' => $argument];
        }
        $document = $this->fixture($argument->kind);
        $document->id = $argument->id;
        $document->revision = $context->expectedRevision;
        $document->status = 'draft';

        return (object) ['document' => $document];
    }

    /**
     * Resolve the trusted resource kind from seeded storage or the save argument.
     *
     * @param   mixed  $resourceId  Candidate resource identifier.
     * @param   mixed  $argument    Vendored semantic argument.
     *
     * @return  string  Canonical artifact kind.
     *
     * @since   2.0.0
     */
    private function kind(mixed $resourceId, mixed $argument): string
    {
        if (is_string($resourceId)) {
            foreach (['1.0.0', '2.0.0'] as $version) {
                $artifact = $this->storage->current('publisher-vector', $resourceId, $version);
                if ($artifact !== null) {
                    return $artifact->kind;
                }
            }
        }
        if ($argument instanceof stdClass && is_string($argument->kind ?? null)) {
            return $argument->kind;
        }

        return 'blueprint';
    }

    /**
     * Load one canonical Studio fixture for a requested artifact kind.
     *
     * @param   string  $kind  Canonical artifact kind.
     *
     * @return  stdClass  Decoded fixture document.
     *
     * @since   2.0.0
     */
    private function fixture(string $kind): stdClass
    {
        $name = match ($kind) {
            'blueprint' => 'blueprint.product.example.json',
            'content-model' => 'content-model.product.example.json',
            'entry' => 'entry.product.example.json',
            default => throw new StudioHostOperationRefused('validation-failed', 'studio.artifact/unsupported-kind'),
        };
        $document = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 2) . '/Fixtures/Studio/testkit/fixtures/' . $name,
            ),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        if (!$document instanceof stdClass) {
            throw new StudioHostOperationRefused('internal', 'studio.test/vector-fixture-invalid');
        }

        return $document;
    }
}

/**
 * Deterministic publication guard probe used by the AP-4 transaction harness.
 *
 * @since  2.0.0
 */
final class StudioArtifactPublicationGuardProbe implements StudioArtifactPublicationGuard
{
    /**
     * Number of guarded App-owned publication attempts.
     *
     * @var    int
     * @since  2.0.0
     */
    public int $calls = 0;

    /**
     * Configure an optional stable refusal.
     *
     * @param  string|null  $diagnosticCode  Host diagnostic to raise, or null to accept.
     *
     * @since  2.0.0
     */
    public function __construct(private readonly ?string $diagnosticCode = null)
    {
    }

    /**
     * Count the attempt and raise the configured compatibility refusal.
     *
     * @param   SiteContext  $site       Trusted owning site.
     * @param   stdClass     $blueprint  Schema-admitted App-owned Blueprint.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function assertPublishable(SiteContext $site, stdClass $blueprint): void
    {
        $this->calls++;
        if ($this->diagnosticCode !== null) {
            throw new StudioHostOperationRefused('conflict', $this->diagnosticCode);
        }
    }
}

/**
 * In-memory audit observer used to prove mutation audit cardinality and redaction.
 *
 * @since  2.0.0
 */
final class StudioArtifactRecoveryRecordingAudit implements AuditRecorder
{
    /**
     * Successfully recorded mutation events.
     *
     * @var    list<AuditEvent>
     * @since  2.0.0
     */
    public array $events = [];

    /**
     * Retain one completed mutation event for assertions.
     *
     * @param   AuditEvent  $event  Completed disclosure-safe mutation event.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(AuditEvent $event): void
    {
        $this->events[] = $event;
    }
}

/**
 * Millisecond test clock driven only by `advance-clock` sequence actions.
 *
 * @since  2.0.0
 */
final class StudioArtifactRecoveryMutableClock implements ClockInterface
{
    /**
     * Current deterministic epoch offset in milliseconds.
     *
     * @var    int
     * @since  2.0.0
     */
    private int $milliseconds = 0;

    /**
     * Return the current deterministic vector instant.
     *
     * @return  DateTimeImmutable  Current vector instant.
     *
     * @since   2.0.0
     */
    public function now(): DateTimeImmutable
    {
        $seconds = intdiv($this->milliseconds, 1000);
        $milliseconds = $this->milliseconds % 1000;

        return DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%d.%03d000', $seconds, $milliseconds),
            new DateTimeZone('UTC'),
        ) ?: throw new \RuntimeException('The vector clock could not be represented.');
    }

    /**
     * Advance the deterministic vector instant.
     *
     * @param   int  $milliseconds  Positive vector-requested offset.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function advance(int $milliseconds): void
    {
        $this->milliseconds += $milliseconds;
    }
}
