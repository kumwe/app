<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Query;

use InvalidArgumentException;

final readonly class CursorPosition
{
    /** @var list<mixed> */
    public array $sortValues;

    /** @param list<mixed> $sortValues */
    public function __construct(
        public string $specificationDigest,
        array $sortValues,
        public string $recordKey,
    ) {
        if (
            preg_match('/^[a-f0-9]{64}$/D', $specificationDigest) !== 1
            || !\Ramsey\Uuid\Uuid::isValid($recordKey)
        ) {
            throw new InvalidArgumentException('A business-record cursor position identity is invalid.');
        }
        if (count($sortValues) > 5) {
            throw new InvalidArgumentException('A business-record cursor contains too many sort values.');
        }
        foreach ($sortValues as $value) {
            QueryValue::assert($value);
        }
        $this->sortValues = array_values($sortValues);
    }

    /** @return array{specification: string, values: list<mixed>, record_key: string} */
    public function toArray(): array
    {
        return [
            'specification' => $this->specificationDigest,
            'values' => array_map(QueryCanonicalizer::value(...), $this->sortValues),
            'record_key' => $this->recordKey,
        ];
    }
}
