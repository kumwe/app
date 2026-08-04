<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class BlockSchemaRegistry
{
    /** @var array<string, BlockSchema> */
    private array $schemas;

    public function __construct(BlockSchema ...$schemas)
    {
        $indexed = [];

        foreach ($schemas as $schema) {
            if (isset($indexed[$schema->type()])) {
                throw new InvalidArgumentException(sprintf('Block schema %s is registered twice.', $schema->type()));
            }

            $indexed[$schema->type()] = $schema;
        }

        $this->schemas = $indexed;
    }

    public function schemaFor(string $type): BlockSchema
    {
        if (!isset($this->schemas[$type])) {
            throw new InvalidBlockDocument(sprintf('Block type %s is not registered.', $type));
        }

        return $this->schemas[$type];
    }
}
