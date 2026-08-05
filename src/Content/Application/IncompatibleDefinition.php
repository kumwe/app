<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

final class IncompatibleDefinition extends \DomainException
{
    /** @param list<string> $breakingChanges */
    public function __construct(public readonly array $breakingChanges)
    {
        parent::__construct('Definition contains breaking changes: ' . implode('; ', $breakingChanges));
    }
}
