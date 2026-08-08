<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;

final readonly class ValidationViolation
{
    public function __construct(public string $field, public string $code, public string $message)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field) !== 1) {
            throw new InvalidArgumentException('A validation violation field is invalid.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{0,126}$/D', $code) !== 1 || $message === '') {
            throw new InvalidArgumentException('A validation violation code or message is invalid.');
        }
    }

    /** @return array{field: string, code: string, message: string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'code' => $this->code, 'message' => $this->message];
    }
}
