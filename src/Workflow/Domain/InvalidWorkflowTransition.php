<?php

declare(strict_types=1);

namespace Kumwe\CMS\Workflow\Domain;

use DomainException;
final class InvalidWorkflowTransition extends DomainException
{
    public function __construct(string|\BackedEnum $from, string|\BackedEnum $to)
    {
        $from = $from instanceof \BackedEnum ? (string) $from->value : $from;
        $to = $to instanceof \BackedEnum ? (string) $to->value : $to;
        parent::__construct(sprintf(
            'The workflow does not allow a transition from %s to %s.',
            $from,
            $to,
        ));
    }
}
