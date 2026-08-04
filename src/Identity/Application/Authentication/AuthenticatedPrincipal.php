<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authentication;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class AuthenticatedPrincipal
{
    public const REQUEST_ATTRIBUTE = self::class;

    /** @var array<string, Capability> */
    private array $capabilities;

    /** @param array<mixed> $capabilities */
    public function __construct(private string $subject, array $capabilities)
    {
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

        if (preg_match($uuidPattern, $subject) !== 1) {
            throw new InvalidArgumentException('An authenticated principal subject must be a canonical UUID.');
        }

        if (!array_is_list($capabilities)) {
            throw new InvalidArgumentException('Principal capabilities must be a list.');
        }

        $indexed = [];

        foreach ($capabilities as $capability) {
            if (!($capability instanceof Capability)) {
                throw new InvalidArgumentException('Principal capabilities must be Capability values.');
            }

            if (isset($indexed[$capability->value()])) {
                throw new InvalidArgumentException(sprintf(
                    'Principal capability %s occurs more than once.',
                    $capability->value(),
                ));
            }

            $indexed[$capability->value()] = $capability;
        }

        ksort($indexed, SORT_STRING);
        $this->capabilities = $indexed;
    }

    /** @param array<mixed> $capabilities */
    public static function fromStrings(string $subject, array $capabilities): self
    {
        if (!array_is_list($capabilities)) {
            throw new InvalidArgumentException('Principal capability names must be a list.');
        }

        $values = [];

        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Principal capability names must be strings.');
            }

            $values[] = Capability::fromString($capability);
        }

        return new self(strtolower($subject), $values);
    }

    public function subject(): string
    {
        return strtolower($this->subject);
    }

    public function hasCapability(Capability $capability): bool
    {
        return isset($this->capabilities[$capability->value()]);
    }

    /** @return list<Capability> */
    public function capabilities(): array
    {
        return array_values($this->capabilities);
    }
}
