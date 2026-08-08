<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;

final readonly class SchemaInstallation
{
    public function __construct(
        public string $definitionId,
        public string $siteIdentifier,
        public string $ownerIdentifier,
        public int $definitionVersion,
        public string $definitionChecksum,
        public string $schemaChecksum,
        public PhysicalSchemaBlueprint $blueprint,
        public SchemaInstallationStatus $status,
        public DateTimeImmutable $installedAt,
        public DateTimeImmutable $updatedAt,
    ) {
        SchemaDocument::assertUuid($definitionId, 'The schema installation definition ID');
        SchemaDocument::assertIdentifier($siteIdentifier, 'The schema installation site');
        SchemaDocument::assertBoundedText($ownerIdentifier, 'The schema installation owner');
        $validOwner = preg_match(
            '#^(?:core|[a-z0-9][a-z0-9._-]{0,190}|[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*)$#D',
            $ownerIdentifier,
        );
        if ($validOwner !== 1) {
            throw new InvalidBusinessSchema('The schema installation owner has an invalid identity contract.');
        }
        if ($definitionVersion < 1) {
            throw new InvalidBusinessSchema('A schema installation requires a published definition version.');
        }
        SchemaDocument::assertChecksum($definitionChecksum, 'The installed definition checksum');
        SchemaDocument::assertChecksum($schemaChecksum, 'The installed physical schema checksum');
        if (
            $blueprint->definitionId !== $definitionId
            || $blueprint->definitionVersion !== $definitionVersion
            || !hash_equals($blueprint->definitionChecksum, $definitionChecksum)
            || !hash_equals($blueprint->checksum(), $schemaChecksum)
        ) {
            throw new InvalidBusinessSchema('A schema installation does not match its physical blueprint.');
        }
        if ($updatedAt < $installedAt) {
            throw new InvalidBusinessSchema('A schema installation cannot be updated before it is installed.');
        }
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'definition_id', 'site_identifier', 'owner_identifier', 'definition_version',
                'definition_checksum', 'schema_checksum', 'blueprint', 'status', 'installed_at', 'updated_at',
            ],
            'A schema installation',
        );
        $status = SchemaInstallationStatus::tryFrom(SchemaDocument::string($document, 'status'))
            ?? throw new InvalidBusinessSchema('A schema installation status is invalid.');
        $blueprint = SchemaDocument::object($document, 'blueprint')
            ?? throw new InvalidBusinessSchema('A schema installation requires its physical blueprint.');

        return new self(
            SchemaDocument::string($document, 'definition_id'),
            SchemaDocument::string($document, 'site_identifier'),
            SchemaDocument::string($document, 'owner_identifier'),
            SchemaDocument::integer($document, 'definition_version'),
            SchemaDocument::string($document, 'definition_checksum'),
            SchemaDocument::string($document, 'schema_checksum'),
            PhysicalSchemaBlueprint::fromArray($blueprint),
            $status,
            SchemaDocument::date(
                SchemaDocument::string($document, 'installed_at'),
                'The schema installation time',
            ),
            SchemaDocument::date(SchemaDocument::string($document, 'updated_at'), 'The schema update time'),
        );
    }

    public function disable(DateTimeImmutable $at): self
    {
        if ($this->status !== SchemaInstallationStatus::Active) {
            throw new InvalidBusinessSchema('Only an active schema installation can be disabled.');
        }

        return $this->withStatus(SchemaInstallationStatus::Disabled, $at);
    }

    public function reactivate(DateTimeImmutable $at): self
    {
        if (!in_array($this->status, [SchemaInstallationStatus::Disabled, SchemaInstallationStatus::Preserved], true)) {
            throw new InvalidBusinessSchema('Only a disabled or preserved schema installation can be reactivated.');
        }

        return $this->withStatus(SchemaInstallationStatus::Active, $at);
    }

    public function preserve(DateTimeImmutable $at): self
    {
        if ($this->status === SchemaInstallationStatus::Failed) {
            throw new InvalidBusinessSchema('A failed schema installation cannot be marked as preserved.');
        }

        return $this->withStatus(SchemaInstallationStatus::Preserved, $at);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'definition_id' => $this->definitionId,
            'site_identifier' => $this->siteIdentifier,
            'owner_identifier' => $this->ownerIdentifier,
            'definition_version' => $this->definitionVersion,
            'definition_checksum' => $this->definitionChecksum,
            'schema_checksum' => $this->schemaChecksum,
            'blueprint' => $this->blueprint->toArray(),
            'status' => $this->status->value,
            'installed_at' => SchemaDocument::formatDate($this->installedAt),
            'updated_at' => SchemaDocument::formatDate($this->updatedAt),
        ];
    }

    private function withStatus(SchemaInstallationStatus $status, DateTimeImmutable $at): self
    {
        return new self(
            $this->definitionId,
            $this->siteIdentifier,
            $this->ownerIdentifier,
            $this->definitionVersion,
            $this->definitionChecksum,
            $this->schemaChecksum,
            $this->blueprint,
            $status,
            $this->installedAt,
            $at,
        );
    }
}
