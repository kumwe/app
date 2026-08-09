<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use DateTimeImmutable;

/**
 * Durable evidence that a named actor approved one exact canonical schema plan.
 *
 * Approval is the boundary between compiling a plan and running DDL, so the evidence has to name the plan
 * by content rather than by identifier: `SchemaPlan` refuses to hold an approval whose checksum is not the
 * checksum of its own operations, which makes an approval useless if the plan is re-planned afterwards.
 * High-impact work additionally carries the confirmation digest produced by the operator's step-up.
 *
 * @since  2.0.0
 */
final readonly class SchemaPlanApproval
{
    /**
     * Capture who approved which canonical plan, when, and under what confirmation.
     *
     * @param   string             $actorIdentifier     Bounded identity of the approving administrator.
     * @param   DateTimeImmutable  $approvedAt          Instant the approval was granted, kept in UTC.
     * @param   string             $approvedChecksum    SHA-256 of the canonical plan this approval binds to.
     * @param   string|null        $confirmationDigest  Step-up confirmation for high-impact work, else null.
     *
     * @throws  InvalidBusinessSchema  When the actor is empty, too long, or holds control characters, or a
     *          supplied checksum or digest is not a lowercase SHA-256 value.
     *
     * @since   2.0.0
     */
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

    /**
     * Rebuild an approval from its persisted document.
     *
     * @param   array<string, mixed>  $document  Stored approval object, as it appears inside a plan document.
     *
     * @return  self  The revalidated approval, with its timestamp normalized to UTC.
     *
     * @throws  InvalidBusinessSchema  When the document carries an unknown property, a required field is
     *          missing or misshapen, or the timestamp cannot be parsed.
     *
     * @since   2.0.0
     */
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

    /**
     * Export the approval in the shape persisted inside a plan document.
     *
     * @return  array<string, ?string>  Approval fields keyed as stored; `confirmation_digest` is null when
     *          the approved plan needed no step-up confirmation.
     *
     * @since   2.0.0
     */
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
