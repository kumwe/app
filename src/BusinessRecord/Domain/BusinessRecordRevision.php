<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Ramsey\Uuid\Uuid;

final readonly class BusinessRecordRevision
{
    /** @var array<string, mixed> */
    private array $snapshot;

    /** @var list<string> */
    private array $changedFields;

    /**
     * @param array<string, mixed> $snapshot
     * @param list<string> $changedFields
     */
    public function __construct(
        public string $revisionId,
        public string $definitionId,
        public int $definitionVersion,
        public string $siteIdentifier,
        public ?string $organizationIdentifier,
        public string $recordKey,
        public string $recordIdentityDigest,
        public int $recordVersion,
        public int $revisionNumber,
        public string $operation,
        array $snapshot,
        array $changedFields,
        public string $actorId,
        public DateTimeImmutable $occurredAt,
    ) {
        if (!Uuid::isValid($revisionId) || !Uuid::isValid($definitionId)) {
            throw new InvalidArgumentException('Business-record revision and definition IDs must be canonical UUIDs.');
        }
        if (!Uuid::isValid($recordKey)) {
            throw new InvalidArgumentException('A business-record revision record key is invalid.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $recordIdentityDigest) !== 1) {
            throw new InvalidArgumentException('A business-record revision identity digest is invalid.');
        }
        if (
            $definitionVersion < 1 || $recordVersion < 1 || $revisionNumber < 1
            || preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $operation) !== 1
        ) {
            throw new InvalidArgumentException('A business-record revision version or operation is invalid.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $siteIdentifier) !== 1) {
            throw new InvalidArgumentException('A business-record revision site is invalid.');
        }
        if (
            $organizationIdentifier !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $organizationIdentifier) !== 1
        ) {
            throw new InvalidArgumentException('A business-record revision organization is invalid.');
        }
        foreach ($snapshot as $handle => $value) {
            if (!is_string($handle) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('A business-record revision contains an invalid field handle.');
            }
            RecordValueGuard::assertValue($value);
        }
        foreach ($changedFields as $handle) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('A business-record revision changed-field handle is invalid.');
            }
        }
        $changedFields = array_values(array_unique($changedFields));
        sort($changedFields, SORT_STRING);
        ksort($snapshot, SORT_STRING);
        $this->snapshot = $snapshot;
        $this->changedFields = $changedFields;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    /** @return list<string> */
    public function changedFields(): array
    {
        return $this->changedFields;
    }

    public function checksum(): string
    {
        try {
            $json = json_encode(
                RecordValueGuard::canonical([
                    'revision_id' => $this->revisionId,
                    'definition_id' => $this->definitionId,
                    'definition_version' => $this->definitionVersion,
                    'site_identifier' => $this->siteIdentifier,
                    'organization_identifier' => $this->organizationIdentifier,
                    'record_key' => $this->recordKey,
                    'record_identity_digest' => $this->recordIdentityDigest,
                    'record_version' => $this->recordVersion,
                    'revision_number' => $this->revisionNumber,
                    'operation' => $this->operation,
                    'snapshot' => $this->snapshot,
                    'changed_fields' => $this->changedFields,
                    'actor_id' => $this->actorId,
                    'occurred_at' => $this->occurredAt->format('Y-m-d\TH:i:s.uP'),
                ]),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'A business-record revision snapshot cannot be checksummed.',
                0,
                $exception,
            );
        }

        return hash('sha256', $json);
    }
}
