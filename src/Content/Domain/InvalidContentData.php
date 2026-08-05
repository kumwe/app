<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

final class InvalidContentData extends \DomainException
{
    /** @param list<string> $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Content data does not satisfy its published schema: ' . implode('; ', $violations));
    }
}
