<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

/** A bounded, disclosure-safe related record or owned-line projection. */
final readonly class BusinessRecordRelationView
{
    /** @var array<string, mixed> */
    public array $values;

    /** @param array<string, mixed> $values */
    public function __construct(
        public string $definitionId,
        public int $definitionVersion,
        public string $recordKey,
        public string $recordId,
        public int $version,
        public ?int $position,
        array $values,
    ) {
        $this->values = $values;
    }
}
