<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

final class AuthorizationResourceOwnershipUnknown extends \RuntimeException
{
    public function __construct(AuthorizationResource $resource)
    {
        parent::__construct(sprintf(
            'No authoritative site ownership exists for %s:%s.',
            $resource->type(),
            $resource->identifier(),
        ));
    }
}
