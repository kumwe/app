<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use Kumwe\CMS\BusinessRecord\Domain\BusinessRecord;

/** Internal repository result; a canonical inverse may update the target row as well. */
final readonly class RelationshipWriteResult
{
    public function __construct(
        public BusinessRecord $source,
        public ?BusinessRecord $target = null,
        public ?string $targetRelationship = null,
    ) {
    }
}
