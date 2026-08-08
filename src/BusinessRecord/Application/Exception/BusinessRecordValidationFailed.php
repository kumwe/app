<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Exception;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\ValidationViolation;

final class BusinessRecordValidationFailed extends BusinessRecordException
{
    /** @var non-empty-list<ValidationViolation> */
    public readonly array $violations;

    /** @param non-empty-list<ValidationViolation> $violations */
    public function __construct(array $violations)
    {
        if ($violations === [] || count($violations) > 256) {
            throw new InvalidArgumentException('Validation failure requires a bounded non-empty violation list.');
        }
        $this->violations = array_values($violations);
        parent::__construct('business_record.validation_failed', 'The business record failed validation.');
    }
}
