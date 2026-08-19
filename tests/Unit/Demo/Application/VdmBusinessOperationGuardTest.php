<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Demo\Application;

use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\Demo\Application\VdmBusinessOperationGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves applied VDM business operations are immutable while later releases may append new operations.
 *
 * @since  2.0.0
 */
#[CoversClass(VdmBusinessOperationGuard::class)]
#[UsesClass(CanonicalDefinitionJson::class)]
final class VdmBusinessOperationGuardTest extends TestCase
{
    /**
     * Accept exact checkpoints and return new declarations for the installer to append normally.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExactCheckpointsAndNewDeclarationsAreAccepted(): void
    {
        $document = $this->document();
        $newRecord = [
            'fixture_key' => 'record.two',
            'record_id' => '00000000-0000-7000-8000-000000000002',
            'definition' => 'site.default.vdm_client',
            'values' => ['name' => 'Second'],
            'idempotency_key' => 'demo:record:two',
        ];
        $document['records'][] = $newRecord;

        $assets = [
            $this->asset('record.one', 'business_record', $document['records'][0], 1),
            $this->asset('relation.one', 'business_relation', $document['relations'][0], 2),
            $this->asset('action.one', 'business_action', $document['actions'][0], 3),
            $this->asset('archive.one', 'business_archive', $document['archives'][0], 4),
            [
                'fixture_key' => 'definition.client',
                'resource_type' => 'business_definition',
                'resource_id' => '00000000-0000-7000-8000-000000000100',
            ],
        ];

        $validated = (new VdmBusinessOperationGuard())->validate($document, $assets);

        self::assertSame($document['records'], $validated['records']);
        self::assertSame($document['relations'], $validated['relations']);
        self::assertSame($document['actions'], $validated['actions']);
        self::assertSame($document['archives'], $validated['archives']);
        self::assertSame($newRecord, $validated['records'][1]);
    }

    /**
     * Reject deleting an operation whose application-service effect was already checkpointed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovedAppliedOperationIsRejected(): void
    {
        $document = $this->document();
        $record = $document['records'][0];
        $document['records'] = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'fixture record.one (business_record) was removed; applied operations are append-only',
        );

        (new VdmBusinessOperationGuard())->validate(
            $document,
            [$this->asset('record.one', 'business_record', $record)],
        );
    }

    /**
     * Reject reusing an applied fixture key for a different operation kind before installation starts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testChangedResourceTypeIsRejected(): void
    {
        $document = $this->document();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'fixture record.one changed resource type from business_relation to business_record',
        );

        (new VdmBusinessOperationGuard())->validate($document, [
            $this->asset('record.one', 'business_relation', $document['records'][0]),
        ]);
    }

    /**
     * Reject changing the service resource targeted by an already-applied fixture.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testChangedResourceIdentityIsRejected(): void
    {
        $document = $this->document();
        $asset = $this->asset('record.one', 'business_record', $document['records'][0]);
        $asset['resource_id'] = '00000000-0000-7000-8000-000000000099';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture record.one changed resource identity');

        (new VdmBusinessOperationGuard())->validate($document, [$asset]);
    }

    /**
     * Reject edited request data even when the old persisted request remains internally consistent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testChangedAppliedRequestIsRejected(): void
    {
        $document = $this->document();
        $stored = $document['records'][0];
        $document['records'][0]['values'] = ['name' => 'Edited release'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'fixture record.one changed its applied request; released operations are append-only',
        );

        (new VdmBusinessOperationGuard())->validate(
            $document,
            [$this->asset('record.one', 'business_record', $stored)],
        );
    }

    /**
     * Reject a corrupt checkpoint whose checksum no longer proves its stored canonical request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCheckpointChecksumMustMatchStoredRequest(): void
    {
        $document = $this->document();
        $asset = $this->asset('record.one', 'business_record', $document['records'][0]);
        $asset['last_applied_checksum'] = str_repeat('a', 64);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checkpoint checksum does not match its stored request');

        (new VdmBusinessOperationGuard())->validate($document, [$asset]);
    }

    /**
     * Reject a decoded checkpoint request whose object keys cannot be reproduced canonically.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCheckpointRequestKeysMustBeStrings(): void
    {
        $document = $this->document();
        $asset = $this->asset('record.one', 'business_record', $document['records'][0]);
        $asset['last_applied_state']['request'][0] = 'invalid';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture record.one has no canonical stored request');

        (new VdmBusinessOperationGuard())->validate($document, [$asset]);
    }

    /**
     * Reject fixture-key collisions across operation kinds instead of allowing one checkpoint to mask another.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFixtureKeysMustBeUniqueAcrossOperationKinds(): void
    {
        $document = $this->document();
        $document['actions'][0]['fixture_key'] = 'record.one';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture record.one is declared more than once');

        (new VdmBusinessOperationGuard())->validate($document, []);
    }

    /**
     * Reject inserting a new operation before a checkpointed operation for the same record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNewOperationsMayOnlyAppendAfterTheAppliedRecordPrefix(): void
    {
        $document = $this->document();
        $newAction = $document['actions'][0];
        $newAction['fixture_key'] = 'action.inserted';
        $newAction['action'] = 'review';
        $newAction['idempotency_key'] = 'demo:action:inserted';
        array_unshift($document['actions'], $newAction);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture action.inserted was inserted before applied fixture action.one');

        (new VdmBusinessOperationGuard())->validate($document, [
            $this->asset('record.one', 'business_record', $document['records'][0], 1),
            $this->asset('relation.one', 'business_relation', $document['relations'][0], 2),
            $this->asset('action.one', 'business_action', $document['actions'][1], 3),
        ]);
    }

    /**
     * Reject reordering applied operations using the result versions written by their original sequence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAppliedOperationsCannotBeReordered(): void
    {
        $document = $this->document();
        $secondAction = $document['actions'][0];
        $secondAction['fixture_key'] = 'action.two';
        $secondAction['action'] = 'complete';
        $secondAction['idempotency_key'] = 'demo:action:two';
        $document['actions'][] = $secondAction;
        $assets = [
            $this->asset('record.one', 'business_record', $document['records'][0], 1),
            $this->asset('relation.one', 'business_relation', $document['relations'][0], 2),
            $this->asset('action.one', 'business_action', $document['actions'][0], 3),
            $this->asset('action.two', 'business_action', $document['actions'][1], 4),
        ];
        $document['actions'] = [$document['actions'][1], $document['actions'][0]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed their applied order at fixture action.one');

        (new VdmBusinessOperationGuard())->validate($document, $assets);
    }

    /**
     * Reject a checkpoint whose row version differs from the version captured in its applied state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCheckpointVersionMustMatchTheStoredState(): void
    {
        $document = $this->document();
        $asset = $this->asset('record.one', 'business_record', $document['records'][0], 1);
        $asset['last_applied_version'] = 2;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checkpoint version does not match its stored request state');

        (new VdmBusinessOperationGuard())->validate($document, [$asset]);
    }

    /**
     * Reject non-positive state versions before they can drive optimistic record writes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCheckpointVersionMustBePositive(): void
    {
        $document = $this->document();
        $asset = $this->asset('record.one', 'business_record', $document['records'][0], 1);
        $asset['last_applied_state']['version'] = 0;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid stored request version');

        (new VdmBusinessOperationGuard())->validate($document, [$asset]);
    }

    /**
     * Reject a fixture key claimed by two resource categories before either category can mutate state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFixtureOwnershipIsUniqueAcrossEveryResourceKind(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'fixture definition.client is claimed by both business_definition and business_record resources',
        );

        (new VdmBusinessOperationGuard())->validateFixtureOwnership([
            ['fixture_key' => 'definition.client', 'resource_type' => 'business_definition'],
            ['fixture_key' => 'definition.client', 'resource_type' => 'business_record'],
        ], []);
    }

    /**
     * Reject unknown and non-string persisted resource types instead of ignoring a corrupt removed fixture.
     *
     * @param   mixed  $resourceType  Corrupt ledger value under test.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('invalidResourceTypes')]
    public function testPersistedResourceTypeVocabularyIsClosed(mixed $resourceType): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture checkpoint record.one has an unknown resource type');

        (new VdmBusinessOperationGuard())->validateFixtureOwnership(
            [['fixture_key' => 'record.one', 'resource_type' => 'business_record']],
            [['fixture_key' => 'record.one', 'resource_type' => $resourceType]],
        );
    }

    /**
     * Supply persisted resource-type values that must never be coerced into the closed vocabulary.
     *
     * @return  array<string, array{0: mixed}>  Invalid string and non-string driver representations.
     *
     * @since   2.0.0
     */
    public static function invalidResourceTypes(): array
    {
        return [
            'unknown string' => ['legacy_business_record'],
            'null' => [null],
            'integer' => [1],
        ];
    }

    /**
     * Reject a recognized non-operation fixture removed from a later projected release.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testClosedFixtureOwnershipRejectsRemovedDefinitionCheckpoint(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'fixture definition.client (business_definition) was removed while its applied checkpoint remains',
        );

        (new VdmBusinessOperationGuard())->validateFixtureOwnership([], [[
            'fixture_key' => 'definition.client',
            'resource_type' => 'business_definition',
        ]]);
    }

    /**
     * Reject scalar values that PHP's generic integer filter would otherwise coerce to version one.
     *
     * @param   mixed  $version  Non-canonical JSON checkpoint version.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('coercibleVersions')]
    public function testCheckpointVersionsRejectCoercibleScalars(mixed $version): void
    {
        $document = $this->document();
        $asset = $this->asset('record.one', 'business_record', $document['records'][0], 1);
        $asset['last_applied_state']['version'] = $version;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid stored request version');

        (new VdmBusinessOperationGuard())->validate($document, [$asset]);
    }

    /**
     * Supply values that are scalar but are not canonical persisted integers.
     *
     * @return  array<string, array{0: mixed}>  Coercible values the exact parser must reject.
     *
     * @since   2.0.0
     */
    public static function coercibleVersions(): array
    {
        return [
            'boolean' => [true],
            'float' => [1.0],
            'leading zero' => ['01'],
            'whitespace' => [' 1'],
        ];
    }

    /**
     * Accept canonical decimal strings returned for BIGINT columns by PostgreSQL drivers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCheckpointVersionsAcceptCanonicalDatabaseStrings(): void
    {
        $document = $this->document();
        $asset = $this->asset('record.one', 'business_record', $document['records'][0], 1);
        $asset['last_applied_version'] = '1';

        $validated = (new VdmBusinessOperationGuard())->validate($document, [$asset]);

        self::assertSame($document['records'], $validated['records']);
    }

    /**
     * Build the smallest complete operation document spanning all irreversible service calls.
     *
     * @return  array{
     *              records: list<array<string, mixed>>,
     *              relations: list<array<string, mixed>>,
     *              actions: list<array<string, mixed>>,
     *              archives: list<array<string, mixed>>
     *          }  Complete released operation document.
     *
     * @since   2.0.0
     */
    private function document(): array
    {
        $recordId = '00000000-0000-7000-8000-000000000001';

        return [
            'records' => [[
                'fixture_key' => 'record.one',
                'record_id' => $recordId,
                'definition' => 'site.default.vdm_client',
                'values' => ['name' => 'First'],
                'idempotency_key' => 'demo:record:one',
            ]],
            'relations' => [[
                'fixture_key' => 'relation.one',
                'definition' => 'site.default.vdm_client',
                'source_record_id' => $recordId,
                'relationship' => 'parent',
                'target_record_id' => '00000000-0000-7000-8000-000000000002',
                'idempotency_key' => 'demo:relation:one',
            ]],
            'actions' => [[
                'fixture_key' => 'action.one',
                'definition' => 'site.default.vdm_client',
                'record_id' => $recordId,
                'action' => 'activate',
                'idempotency_key' => 'demo:action:one',
            ]],
            'archives' => [[
                'fixture_key' => 'archive.one',
                'definition' => 'site.default.vdm_client',
                'record_id' => $recordId,
                'idempotency_key' => 'demo:archive:one',
            ]],
        ];
    }

    /**
     * Build one ledger row exactly as the installer records a successful business operation.
     *
     * @param   string                $fixtureKey    Stable operation fixture key.
     * @param   string                $resourceType  Exact operation resource type.
     * @param   array<string, mixed>  $request       Canonical released request.
     * @param   positive-int          $version       Record version produced by the operation.
     *
     * @return  array<string, mixed>  Complete operation checkpoint.
     *
     * @since   2.0.0
     */
    private function asset(string $fixtureKey, string $resourceType, array $request, int $version = 1): array
    {
        $resourceId = $request['source_record_id'] ?? $request['record_id'] ?? null;
        self::assertIsString($resourceId);

        return [
            'fixture_key' => $fixtureKey,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'last_applied_checksum' => CanonicalDefinitionJson::checksum($request),
            'last_applied_version' => $version,
            'last_applied_state' => ['request' => $request, 'version' => $version],
        ];
    }
}
