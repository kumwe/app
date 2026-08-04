<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Content;

use RuntimeException;

final class PreconditionFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The supplied If-Match value does not identify the current content version.');
    }
}
