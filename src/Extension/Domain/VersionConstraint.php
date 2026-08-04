<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class VersionConstraint implements Stringable
{
    /** @var list<array{operator: string, version: SemanticVersion}> */
    private array $comparators;

    /** @param list<array{operator: string, version: SemanticVersion}> $comparators */
    private function __construct(private string $expression, array $comparators)
    {
        $this->comparators = $comparators;
    }

    public static function fromString(string $expression): self
    {
        $expression = trim($expression);

        if (strlen($expression) > 255) {
            throw new InvalidArgumentException('A version constraint cannot exceed 255 characters.');
        }

        if ($expression === '*' || $expression === '') {
            return new self('*', []);
        }

        if (preg_match('/^[~^]\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $expression) === 1) {
            return self::fromShorthand($expression);
        }

        $comparators = [];

        foreach (preg_split('/\s+/', $expression) ?: [] as $token) {
            if (preg_match('/^(<=|>=|<|>|=)?(.+)$/D', $token, $matches) !== 1) {
                throw new InvalidArgumentException('The version constraint is invalid.');
            }

            $comparators[] = [
                'operator' => $matches[1] === '' ? '=' : $matches[1],
                'version' => SemanticVersion::fromString($matches[2]),
            ];
        }

        return new self($expression, $comparators);
    }

    public function accepts(SemanticVersion $candidate): bool
    {
        foreach ($this->comparators as $comparator) {
            $comparison = $candidate->compare($comparator['version']);
            $accepted = match ($comparator['operator']) {
                '=' => $comparison === 0,
                '>' => $comparison > 0,
                '>=' => $comparison >= 0,
                '<' => $comparison < 0,
                '<=' => $comparison <= 0,
                default => false,
            };

            if (!$accepted) {
                return false;
            }
        }

        return true;
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    private static function fromShorthand(string $expression): self
    {
        $operator = $expression[0];
        $minimum = SemanticVersion::fromString(substr($expression, 1));

        if ($operator === '~') {
            $maximum = sprintf('%d.%d.0', $minimum->major(), self::increment($minimum->minor()));
        } elseif ($minimum->major() > 0) {
            $maximum = sprintf('%d.0.0', self::increment($minimum->major()));
        } elseif ($minimum->minor() > 0) {
            $maximum = sprintf('0.%d.0', self::increment($minimum->minor()));
        } else {
            $maximum = sprintf('0.0.%d', self::increment($minimum->patch()));
        }

        return new self($expression, [
            ['operator' => '>=', 'version' => $minimum],
            ['operator' => '<', 'version' => SemanticVersion::fromString($maximum)],
        ]);
    }

    private static function increment(int $component): int
    {
        if ($component === PHP_INT_MAX) {
            throw new InvalidArgumentException('A shorthand constraint cannot increment the maximum integer value.');
        }

        return $component + 1;
    }
}
