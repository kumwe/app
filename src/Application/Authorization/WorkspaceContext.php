<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;

/**
 * Optional server-resolved workspace nested inside an authenticated organization.
 *
 * @since  2.0.0
 */
final readonly class WorkspaceContext
{
    /**
     * Hold a normalized workspace identifier.
     *
     * @param  string  $identifier  Validated lowercase workspace identifier.
     *
     * @since  2.0.0
     */
    private function __construct(private string $identifier)
    {
    }

    /**
     * Normalize and validate a workspace identifier read from trusted storage.
     *
     * @param   string  $identifier  Candidate workspace identifier.
     *
     * @return  self  Validated workspace context.
     *
     * @throws  InvalidArgumentException  When the identifier is not bounded and portable.
     *
     * @since   2.0.0
     */
    public static function fromString(string $identifier): self
    {
        $identifier = strtolower(trim($identifier));
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('A workspace context must be a valid non-empty identifier.');
        }

        return new self($identifier);
    }

    /**
     * Expose the normalized workspace identifier.
     *
     * @return  string  Identifier safe for exact comparison and query binding.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->identifier;
    }
}
