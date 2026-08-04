<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Domain;

use DomainException;
use Kumwe\CMS\Content\Domain\ContentStatus;

final class InvalidWorkflowTransition extends DomainException
{
    public function __construct(ContentStatus $from, ContentStatus $to)
    {
        parent::__construct(sprintf(
            'The workflow does not allow a transition from %s to %s.',
            $from->value,
            $to->value,
        ));
    }
}
