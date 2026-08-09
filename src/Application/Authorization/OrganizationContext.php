<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use InvalidArgumentException;

/**
 * Server-resolved organization in which an authorized unit of work executes.
 *
 * Unlike a business command's expected organization value, this object belongs to the authenticated
 * execution context. Only authentication and membership adapters holding the kernel provenance may put
 * it on an `ExecutionContext`, so submitted identifiers can be compared with it but never establish it.
 *
 * @since  2.0.0
 */
final readonly class OrganizationContext
{
    /**
     * Hold a normalized organization identifier.
     *
     * @param  string  $identifier  Validated lowercase organization identifier.
     *
     * @since  2.0.0
     */
    private function __construct(private string $identifier)
    {
    }

    /**
     * Normalize and validate a stored organization identifier.
     *
     * @param   string  $identifier  Identifier read from trusted membership storage.
     *
     * @return  self  Validated organization context.
     *
     * @throws  InvalidArgumentException  When the identifier is not a bounded portable identifier.
     *
     * @since   2.0.0
     */
    public static function fromString(string $identifier): self
    {
        $identifier = strtolower(trim($identifier));
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{0,190}$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('An organization context must be a valid non-empty identifier.');
        }

        return new self($identifier);
    }

    /**
     * Expose the normalized organization identifier.
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
