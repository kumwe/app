<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;

final readonly class SchemaChunkResult
{
    /** @var array<string, bool|int|string>|null */
    public ?array $cursor;

    /** @param array<string, bool|int|string>|null $cursor */
    public function __construct(?array $cursor, public int $processed, public bool $complete)
    {
        if ($processed < 0 || $processed > 10_000 || (!$complete && $cursor === null)) {
            throw new \InvalidArgumentException('A schema chunk result has invalid progress.');
        }
        if ($cursor !== null) {
            CanonicalDefinitionJson::encode($cursor);
        }
        $this->cursor = $cursor;
    }
}
