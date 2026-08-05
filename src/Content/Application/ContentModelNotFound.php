<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Application;

final class ContentModelNotFound extends \RuntimeException
{
    public function __construct(string $kind, string $identifier, ?int $version = null)
    {
        parent::__construct(sprintf(
            '%s "%s"%s was not found.',
            ucfirst($kind),
            $identifier,
            $version === null ? '' : ' version ' . $version,
        ));
    }
}
