<?php

declare(strict_types=1);

namespace Kumwe\CMS\Demo\Application;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use RuntimeException;

/**
 * Enforces the append-only release contract for already-applied VDM business operations.
 *
 * Record creation, relationship, workflow-action, and archive declarations describe irreversible
 * application-service calls rather than mutable desired state. A later release may append new calls, but
 * cannot safely edit or remove one already checkpointed. This guard verifies the complete persisted
 * identity and canonical request before the installer performs any mutation, preventing a changed release
 * from being reported as reconciled merely because a fixture key already exists.
 *
 * @since  2.0.0
 */
final readonly class VdmBusinessOperationGuard
{
    /**
     * Complete resource-type vocabulary owned by the VDM profile ledger.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array RESOURCE_TYPES = [
        'business_definition',
        'resource_policy',
        'business_record',
        'business_relation',
        'business_action',
        'business_archive',
    ];

    /**
     * Validate every desired operation and every persisted operation checkpoint as one immutable set.
     *
     * Uncheckpointed declarations remain eligible for normal installation, including declarations appended
     * by a newer manifest. A checkpointed declaration must retain its fixture key, operation kind, resource
     * identity, request bytes, and request checksum. Previously applied declarations must remain present.
     *
     * @param   array<string, mixed>        $document  Projected VDM records document.
     * @param   list<array<string, mixed>>  $assets    All persisted assets for the VDM dataset.
     *
     * @return  array{
     *              records: list<array<string, mixed>>,
     *              relations: list<array<string, mixed>>,
     *              actions: list<array<string, mixed>>,
     *              archives: list<array<string, mixed>>
     *          }  Validated operation declarations in their released order.
     *
     * @throws  RuntimeException  When a declaration is malformed, duplicates another fixture key, or an
     *          applied operation was removed, changed, or has a corrupt checkpoint.
     *
     * @since   2.0.0
     */
    public function validate(array $document, array $assets): array
    {
        $records = $this->declarations($document, 'records', 512);
        $relations = $this->declarations($document, 'relations', 1_024);
        $actions = $this->declarations($document, 'actions', 1_024);
        $archives = $this->declarations($document, 'archives', 512);

        /** @var array<string, array{resource_type: string, resource_id: string, request: array<string, mixed>}> */
        $desired = [];
        /** @var array<string, list<string>> $sequences */
        $sequences = [];
        $this->addDeclarations($desired, $sequences, $records, 'business_record', 'record_id');
        $this->addDeclarations($desired, $sequences, $relations, 'business_relation', 'source_record_id');
        $this->addDeclarations($desired, $sequences, $actions, 'business_action', 'record_id');
        $this->addDeclarations($desired, $sequences, $archives, 'business_archive', 'record_id');

        /** @var array<string, array<string, mixed>> $assetsByFixture */
        $assetsByFixture = [];
        foreach ($assets as $offset => $asset) {
            $fixtureKey = $this->requiredAssetString($asset, 'fixture_key', $offset);
            if (isset($assetsByFixture[$fixtureKey])) {
                throw new RuntimeException(sprintf(
                    'VDM business operation checkpoint %s is duplicated.',
                    $fixtureKey,
                ));
            }
            $assetsByFixture[$fixtureKey] = $asset;
        }

        /** @var array<string, int> $appliedVersions */
        $appliedVersions = [];
        foreach ($desired as $fixtureKey => $operation) {
            $asset = $assetsByFixture[$fixtureKey] ?? null;
            if ($asset !== null) {
                $appliedVersions[$fixtureKey] = $this->assertCheckpoint($fixtureKey, $operation, $asset);
            }
        }
        foreach ($assetsByFixture as $fixtureKey => $asset) {
            $resourceType = $asset['resource_type'] ?? null;
            if (!is_string($resourceType) || !$this->isOperationType($resourceType)) {
                continue;
            }
            if (!isset($desired[$fixtureKey])) {
                throw new RuntimeException(sprintf(
                    'VDM business operation fixture %s (%s) was removed; applied operations are append-only.',
                    $fixtureKey,
                    $resourceType,
                ));
            }
        }
        $this->assertAppendOnlySequences($sequences, $appliedVersions);

        return [
            'records' => $records,
            'relations' => $relations,
            'actions' => $actions,
            'archives' => $archives,
        ];
    }

    /**
     * Validate the closed fixture namespace shared by every VDM resource kind.
     *
     * Definitions, generated policies, and irreversible record operations all persist into the same
     * fixture-key primary key. The complete projected release must therefore claim each key exactly once,
     * and every durable checkpoint must retain a recognized type and a matching desired claim.
     *
     * @param   list<array<string, mixed>>  $claims  Desired fixture and resource-type claims.
     * @param   list<array<string, mixed>>  $assets  All persisted assets for the VDM dataset.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a desired key is duplicated, a type is unknown, or an applied
     *          checkpoint was removed or changed type.
     *
     * @since   2.0.0
     */
    public function validateFixtureOwnership(array $claims, array $assets): void
    {
        /** @var array<string, string> $desiredTypes */
        $desiredTypes = [];
        foreach ($claims as $offset => $claim) {
            $fixtureKey = $this->requiredClaimString($claim, 'fixture_key', $offset);
            $resourceType = $this->requiredClaimString($claim, 'resource_type', $offset);
            if (!$this->isKnownResourceType($resourceType)) {
                throw new RuntimeException(sprintf(
                    'VDM fixture claim %s has unknown resource type %s.',
                    $fixtureKey,
                    $resourceType,
                ));
            }
            if (isset($desiredTypes[$fixtureKey])) {
                throw new RuntimeException(sprintf(
                    'VDM fixture %s is claimed by both %s and %s resources.',
                    $fixtureKey,
                    $desiredTypes[$fixtureKey],
                    $resourceType,
                ));
            }
            $desiredTypes[$fixtureKey] = $resourceType;
        }

        /** @var array<string, true> $persistedFixtures */
        $persistedFixtures = [];
        foreach ($assets as $offset => $asset) {
            $fixtureKey = $this->requiredAssetString($asset, 'fixture_key', $offset);
            if (isset($persistedFixtures[$fixtureKey])) {
                throw new RuntimeException(sprintf('VDM fixture checkpoint %s is duplicated.', $fixtureKey));
            }
            $persistedFixtures[$fixtureKey] = true;

            $resourceType = $asset['resource_type'] ?? null;
            if (!is_string($resourceType) || !$this->isKnownResourceType($resourceType)) {
                throw new RuntimeException(sprintf(
                    'VDM fixture checkpoint %s has an unknown resource type.',
                    $fixtureKey,
                ));
            }
            $desiredType = $desiredTypes[$fixtureKey] ?? null;
            if ($desiredType === null) {
                throw new RuntimeException(sprintf(
                    'VDM fixture %s (%s) was removed while its applied checkpoint remains.',
                    $fixtureKey,
                    $resourceType,
                ));
            }
            if ($desiredType !== $resourceType) {
                throw new RuntimeException(sprintf(
                    'VDM fixture %s changed resource type from %s to %s.',
                    $fixtureKey,
                    $resourceType,
                    $desiredType,
                ));
            }
        }
    }

    /**
     * Read and normalize one bounded declaration list from the records document.
     *
     * @param   array<string, mixed>  $document  Projected records document.
     * @param   string                $key       Declaration collection name.
     * @param   int                   $maximum   Largest accepted collection.
     *
     * @return  list<array<string, mixed>>  Object-shaped declarations in manifest order.
     *
     * @throws  RuntimeException  When the collection or one of its declarations is malformed.
     *
     * @since   2.0.0
     */
    private function declarations(array $document, string $key, int $maximum): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new RuntimeException(sprintf('The VDM demo list %s is invalid.', $key));
        }

        $declarations = [];
        foreach ($value as $offset => $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new RuntimeException(sprintf(
                    'The VDM demo %s declaration at offset %d is invalid.',
                    $key,
                    $offset,
                ));
            }
            $declaration = [];
            foreach ($candidate as $field => $item) {
                if (!is_string($field)) {
                    throw new RuntimeException(sprintf(
                        'The VDM demo %s declaration at offset %d has a non-string field.',
                        $key,
                        $offset,
                    ));
                }
                $declaration[$field] = $item;
            }
            $declarations[] = $declaration;
        }

        return $declarations;
    }

    /**
     * Add one operation kind to the immutable desired checkpoint map.
     *
     * @param   array<string, array{
     *              resource_type: string,
     *              resource_id: string,
     *              request: array<string, mixed>
     *          }>                                   &$desired     Desired operations keyed by fixture key.
     * @param   array<string, list<string>>  &$sequences     Fixture order keyed by affected record ID.
     * @param   list<array<string, mixed>>  $declarations   One released operation collection.
     * @param   string                      $resourceType   Exact ledger resource type.
     * @param   string                      $identityField  Manifest field holding the affected record ID.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an identity is absent or a fixture key appears more than once.
     *
     * @since   2.0.0
     */
    private function addDeclarations(
        array &$desired,
        array &$sequences,
        array $declarations,
        string $resourceType,
        string $identityField,
    ): void {
        foreach ($declarations as $declaration) {
            $fixtureKey = $this->requiredDeclarationString($declaration, 'fixture_key', $resourceType);
            if (isset($desired[$fixtureKey])) {
                throw new RuntimeException(sprintf(
                    'VDM business operation fixture %s is declared more than once.',
                    $fixtureKey,
                ));
            }
            $this->canonicalRequest($fixtureKey, $declaration, 'released request');
            $resourceId = $this->requiredDeclarationString($declaration, $identityField, $resourceType);
            $desired[$fixtureKey] = [
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'request' => $declaration,
            ];
            $sequences[$resourceId] ??= [];
            $sequences[$resourceId][] = $fixtureKey;
        }
    }

    /**
     * Prove one persisted checkpoint describes the exact released operation it would otherwise skip.
     *
     * @param   string                $fixtureKey  Stable fixture key under validation.
     * @param   array{
     *              resource_type: string,
     *              resource_id: string,
     *              request: array<string, mixed>
     *          }                             $desired     Released operation identity and request.
     * @param   array<string, mixed>  $asset       Persisted ledger checkpoint.
     *
     * @return  positive-int  Persisted result version used to authenticate per-record operation order.
     *
     * @throws  RuntimeException  When identity, request, or checksum differs or is malformed.
     *
     * @since   2.0.0
     */
    private function assertCheckpoint(string $fixtureKey, array $desired, array $asset): int
    {
        $storedType = $this->requiredCheckpointString($asset, 'resource_type', $fixtureKey);
        if ($storedType !== $desired['resource_type']) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s changed resource type from %s to %s; '
                    . 'applied operations are immutable.',
                $fixtureKey,
                $storedType,
                $desired['resource_type'],
            ));
        }

        $storedId = $this->requiredCheckpointString($asset, 'resource_id', $fixtureKey);
        if ($storedId !== $desired['resource_id']) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s changed resource identity from %s to %s; '
                    . 'applied operations are immutable.',
                $fixtureKey,
                $storedId,
                $desired['resource_id'],
            ));
        }

        $state = $asset['last_applied_state'] ?? null;
        if (!is_array($state) || array_is_list($state)) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s has an invalid checkpoint state.',
                $fixtureKey,
            ));
        }
        $storedRequest = $state['request'] ?? null;
        if (!is_array($storedRequest) || array_is_list($storedRequest)) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s has no canonical stored request.',
                $fixtureKey,
            ));
        }
        /** @var array<string, mixed> $normalizedRequest */
        $normalizedRequest = [];
        foreach ($storedRequest as $field => $value) {
            if (!is_string($field)) {
                throw new RuntimeException(sprintf(
                    'VDM business operation fixture %s has no canonical stored request.',
                    $fixtureKey,
                ));
            }
            $normalizedRequest[$field] = $value;
        }

        $storedChecksum = $asset['last_applied_checksum'] ?? null;
        if (!is_string($storedChecksum) || preg_match('/^[a-f0-9]{64}$/D', $storedChecksum) !== 1) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s has an invalid request checksum.',
                $fixtureKey,
            ));
        }
        $storedCanonical = $this->canonicalRequest($fixtureKey, $normalizedRequest, 'stored request');
        $requestChecksum = hash('sha256', $storedCanonical);
        if (!hash_equals($storedChecksum, $requestChecksum)) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s checkpoint checksum does not match its stored request.',
                $fixtureKey,
            ));
        }
        $desiredCanonical = $this->canonicalRequest($fixtureKey, $desired['request'], 'released request');
        if ($storedCanonical !== $desiredCanonical) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s changed its applied request; released operations are append-only.',
                $fixtureKey,
            ));
        }

        $stateVersion = $this->positiveInteger($state['version'] ?? null, $fixtureKey, 'stored request version');
        $assetVersion = $this->positiveInteger(
            $asset['last_applied_version'] ?? null,
            $fixtureKey,
            'checkpoint version',
        );
        if ($stateVersion !== $assetVersion) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s checkpoint version does not match its stored request state.',
                $fixtureKey,
            ));
        }

        return $stateVersion;
    }

    /**
     * Require every applied per-record sequence to remain an ordered prefix of the released sequence.
     *
     * @param   array<string, list<string>>  $sequences        Desired fixture order by affected record ID.
     * @param   array<string, int>           $appliedVersions  Persisted result version by applied fixture.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a new operation was inserted before an applied one, or applied
     *          operations were reordered relative to their monotonic result versions.
     *
     * @since   2.0.0
     */
    private function assertAppendOnlySequences(array $sequences, array $appliedVersions): void
    {
        foreach ($sequences as $recordId => $fixtureKeys) {
            $firstUnapplied = null;
            $previousVersion = null;
            foreach ($fixtureKeys as $fixtureKey) {
                $version = $appliedVersions[$fixtureKey] ?? null;
                if ($version === null) {
                    $firstUnapplied ??= $fixtureKey;
                    continue;
                }
                if ($firstUnapplied !== null) {
                    throw new RuntimeException(sprintf(
                        'VDM business operation fixture %s was inserted before applied fixture %s for record %s.',
                        $firstUnapplied,
                        $fixtureKey,
                        $recordId,
                    ));
                }
                if ($previousVersion !== null && $version <= $previousVersion) {
                    throw new RuntimeException(sprintf(
                        'VDM business operations for record %s changed their applied order at fixture %s.',
                        $recordId,
                        $fixtureKey,
                    ));
                }
                $previousVersion = $version;
            }
        }
    }

    /**
     * Normalize one persisted positive integer without accepting fractions or loose numeric strings.
     *
     * @param   mixed   $value       Decoded JSON or database-driver value.
     * @param   string  $fixtureKey  Stable fixture key used in diagnostics.
     * @param   string  $name        Persisted field role used in diagnostics.
     *
     * @return  positive-int  Exact positive integer value.
     *
     * @throws  RuntimeException  When the value is not an exact positive integer.
     *
     * @since   2.0.0
     */
    private function positiveInteger(mixed $value, string $fixtureKey, string $name): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
        } else {
            $integer = false;
        }
        if (!is_int($integer) || $integer < 1) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s has an invalid %s.',
                $fixtureKey,
                $name,
            ));
        }

        return $integer;
    }

    /**
     * Encode one operation request through the same canonical contract used when its checkpoint is written.
     *
     * @param   string                $fixtureKey  Stable fixture key used in diagnostics.
     * @param   array<string, mixed>  $request     Stored or newly released operation request.
     * @param   string                $name        Request role used in diagnostics.
     *
     * @return  string  Byte-stable canonical request JSON.
     *
     * @throws  RuntimeException  When the request contains a value the canonical ledger cannot reproduce.
     *
     * @since   2.0.0
     */
    private function canonicalRequest(string $fixtureKey, array $request, string $name): string
    {
        try {
            return CanonicalDefinitionJson::encode($request);
        } catch (InvalidBusinessDefinition $exception) {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s has an invalid canonical %s.',
                $fixtureKey,
                $name,
            ), 0, $exception);
        }
    }

    /**
     * Require one non-empty declaration identity with operation-specific diagnostics.
     *
     * @param   array<string, mixed>  $declaration   Released operation declaration.
     * @param   string                $field         Required identity field.
     * @param   string                $resourceType  Operation resource type.
     *
     * @return  string  Validated field value.
     *
     * @throws  RuntimeException  When the field is absent or empty.
     *
     * @since   2.0.0
     */
    private function requiredDeclarationString(array $declaration, string $field, string $resourceType): string
    {
        $value = $declaration[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'The VDM %s declaration field %s is invalid.',
                $resourceType,
                $field,
            ));
        }

        return $value;
    }

    /**
     * Require one non-empty fixture ownership claim field.
     *
     * @param   array<string, mixed>  $claim   Desired fixture ownership claim.
     * @param   string                $field   Required field name.
     * @param   int                   $offset  Claim offset used before its identity is known.
     *
     * @return  string  Validated claim field.
     *
     * @throws  RuntimeException  When the claim field is absent or empty.
     *
     * @since   2.0.0
     */
    private function requiredClaimString(array $claim, string $field, int $offset): string
    {
        $value = $claim[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'The VDM fixture claim at offset %d has an invalid %s.',
                $offset,
                $field,
            ));
        }

        return $value;
    }

    /**
     * Require one non-empty persisted checkpoint field.
     *
     * @param   array<string, mixed>  $asset       Persisted checkpoint.
     * @param   string                $field       Required field name.
     * @param   string                $fixtureKey  Fixture key used in diagnostics.
     *
     * @return  string  Validated checkpoint field.
     *
     * @throws  RuntimeException  When the checkpoint field is absent or empty.
     *
     * @since   2.0.0
     */
    private function requiredCheckpointString(array $asset, string $field, string $fixtureKey): string
    {
        $value = $asset[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'VDM business operation fixture %s has an invalid checkpoint %s.',
                $fixtureKey,
                $field,
            ));
        }

        return $value;
    }

    /**
     * Require one fixture key while indexing raw ledger assets.
     *
     * @param   array<string, mixed>  $asset   Persisted asset row.
     * @param   string                $field   Required field name.
     * @param   int                   $offset  Asset offset used before its identity is known.
     *
     * @return  string  Validated asset fixture key.
     *
     * @throws  RuntimeException  When the ledger row has no usable fixture key.
     *
     * @since   2.0.0
     */
    private function requiredAssetString(array $asset, string $field, int $offset): string
    {
        $value = $asset[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf(
                'The VDM business operation asset at offset %d has an invalid %s.',
                $offset,
                $field,
            ));
        }

        return $value;
    }

    /**
     * Identify resource types representing append-only business-service calls.
     *
     * @param   string  $resourceType  Persisted ledger resource type.
     *
     * @return  bool  Whether the resource is governed by the append-only operation contract.
     *
     * @since   2.0.0
     */
    private function isOperationType(string $resourceType): bool
    {
        return in_array($resourceType, [
            'business_record',
            'business_relation',
            'business_action',
            'business_archive',
        ], true);
    }

    /**
     * Identify resource types admitted to the VDM profile ledger.
     *
     * @param   string  $resourceType  Desired or persisted ledger resource type.
     *
     * @return  bool  Whether the type belongs to the closed VDM resource vocabulary.
     *
     * @since   2.0.0
     */
    private function isKnownResourceType(string $resourceType): bool
    {
        return in_array($resourceType, self::RESOURCE_TYPES, true);
    }
}
