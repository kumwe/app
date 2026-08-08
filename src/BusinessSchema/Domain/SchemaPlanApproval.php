<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;

final readonly class SchemaPlanApproval
{
    public function __construct(
        public string $actorIdentifier,
        public DateTimeImmutable $approvedAt,
        public string $approvedChecksum,
        public ?string $confirmationDigest = null,
    ) {
        SchemaDocument::assertBoundedText($actorIdentifier, 'The schema-plan approver');
        SchemaDocument::assertChecksum($approvedChecksum, 'The approved schema-plan checksum');
        SchemaDocument::assertChecksum($confirmationDigest, 'The schema-plan confirmation digest', true);
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            ['actor_identifier', 'approved_at', 'approved_checksum', 'confirmation_digest'],
            'A schema-plan approval',
        );

        return new self(
            SchemaDocument::string($document, 'actor_identifier'),
            SchemaDocument::date(SchemaDocument::string($document, 'approved_at'), 'The schema-plan approval time'),
            SchemaDocument::string($document, 'approved_checksum'),
            SchemaDocument::nullableString($document, 'confirmation_digest'),
        );
    }

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return [
            'actor_identifier' => $this->actorIdentifier,
            'approved_at' => SchemaDocument::formatDate($this->approvedAt),
            'approved_checksum' => $this->approvedChecksum,
            'confirmation_digest' => $this->confirmationDigest,
        ];
    }
}
