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

    /** @param list<Capability> $capabilities */
    public function __construct(private string $subject, array $capabilities)
    {
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

        if (preg_match($uuidPattern, $subject) !== 1) {
            throw new InvalidArgumentException('An authenticated principal subject must be a canonical UUID.');
        }

        if (!array_is_list($capabilities)) {
            throw new InvalidArgumentException('Principal capabilities must be an ordered list.');
        }

        $indexed = [];

        foreach ($capabilities as $capability) {
            if (!$capability instanceof Capability) {
                throw new InvalidArgumentException('Principal capabilities must be validated Capability values.');
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

    /** @param list<string> $capabilities */
    public static function fromStrings(string $subject, array $capabilities): self
    {
        if (!array_is_list($capabilities)) {
            throw new InvalidArgumentException('Principal capability strings must be an ordered list.');
        }

        return new self(
            strtolower($subject),
            array_map(
                static fn (string $capability): Capability => Capability::fromString($capability),
                $capabilities,
            ),
        );
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
