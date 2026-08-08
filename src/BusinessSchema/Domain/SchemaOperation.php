<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Domain;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class SchemaOperation
{
    private const RECOVERY_IMPLICATIONS = [
        'none',
        'compensate_safe_addition',
        'resume_required',
        'restore_required',
        'manual_reconciliation',
    ];

    /** @var array<string, mixed>|null */
    public ?array $before;

    /** @var array<string, mixed>|null */
    public ?array $after;

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function __construct(
        public int $ordinal,
        public SchemaOperationKind $kind,
        public SchemaRisk $risk,
        public string $table,
        public string $subject,
        ?array $before = null,
        ?array $after = null,
        public bool $requiresBackfill = false,
        public string $recoveryImplication = 'none',
    ) {
        if ($ordinal < 1 || $ordinal > 100_000) {
            throw new InvalidBusinessSchema('A schema operation ordinal is outside the supported bounds.');
        }
        SchemaDocument::assertIdentifier($table, 'The schema operation table');
        if (
            strlen($subject) > 512
            || preg_match('#^(?:/[a-z0-9._:/-]+|[a-z][a-z0-9]*(?:[._:-][a-z0-9]+)*)$#D', $subject) !== 1
        ) {
            throw new InvalidBusinessSchema('A schema operation subject must be a validated metadata path.');
        }
        if (!in_array($recoveryImplication, self::RECOVERY_IMPLICATIONS, true)) {
            throw new InvalidBusinessSchema('A schema operation recovery implication is invalid.');
        }
        if ($requiresBackfill && $risk === SchemaRisk::OnlineSafeAdditive) {
            throw new InvalidBusinessSchema('A backfill operation cannot be classified as online-safe additive.');
        }
        foreach ([$before, $after] as $state) {
            if ($state !== null) {
                SchemaDocument::assertObjectValue($state, 'Schema operation before/after state');
                CanonicalDefinitionJson::encode($state);
            }
        }
        $this->before = $before;
        $this->after = $after;
    }

    /** @param array<string, mixed> $document */
    public static function fromArray(array $document): self
    {
        SchemaDocument::assertOnly(
            $document,
            [
                'ordinal', 'kind', 'risk', 'table', 'subject', 'before', 'after', 'requires_backfill',
                'recovery_implication', 'checksum',
            ],
            'A schema operation',
        );
        $kind = SchemaOperationKind::tryFrom(SchemaDocument::string($document, 'kind'))
            ?? throw new InvalidBusinessSchema('A schema operation kind is invalid.');
        $risk = SchemaRisk::tryFrom(SchemaDocument::string($document, 'risk'))
            ?? throw new InvalidBusinessSchema('A schema operation risk is invalid.');
        $operation = new self(
            SchemaDocument::integer($document, 'ordinal'),
            $kind,
            $risk,
            SchemaDocument::string($document, 'table'),
            SchemaDocument::string($document, 'subject'),
            SchemaDocument::object($document, 'before', true),
            SchemaDocument::object($document, 'after', true),
            SchemaDocument::boolean($document, 'requires_backfill'),
            SchemaDocument::string($document, 'recovery_implication'),
        );
        $checksum = $document['checksum'] ?? null;
        if ($checksum !== null && (!is_string($checksum) || !hash_equals($operation->checksum(), $checksum))) {
            throw new InvalidBusinessSchema('A persisted schema operation checksum does not match its content.');
        }

        return $operation;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ordinal' => $this->ordinal,
            'kind' => $this->kind->value,
            'risk' => $this->risk->value,
            'table' => $this->table,
            'subject' => $this->subject,
            'before' => $this->before,
            'after' => $this->after,
            'requires_backfill' => $this->requiresBackfill,
            'recovery_implication' => $this->recoveryImplication,
        ];
    }

    /** @return array<string, mixed> */
    public function persistedArray(): array
    {
        return [...$this->toArray(), 'checksum' => $this->checksum()];
    }

    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }
}
