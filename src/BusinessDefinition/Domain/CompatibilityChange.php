<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

final readonly class CompatibilityChange
{
    public function __construct(
        public string $path,
        public CompatibilityClassification $classification,
        public string $message,
    ) {
        if (preg_match('#^/[a-z0-9_./-]+$#D', $path) !== 1 || $message === '' || strlen($message) > 500) {
            throw new InvalidBusinessDefinition('A compatibility change is invalid.');
        }
    }

    /** @return array{path: string, classification: string, message: string} */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'classification' => $this->classification->value,
            'message' => $this->message,
        ];
    }
}
