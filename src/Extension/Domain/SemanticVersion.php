<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;
use Stringable;

final readonly class SemanticVersion implements Stringable
{
    /** @param list<string> $preRelease */
    private function __construct(
        private int $major,
        private int $minor,
        private int $patch,
        private array $preRelease,
        private ?string $build,
    ) {
    }

    public static function fromString(string $value): self
    {
        $value = trim($value);

        if (strlen($value) > 128) {
            throw new InvalidArgumentException('A semantic version cannot exceed 128 characters.');
        }

        $pattern = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)'
            . '(?:-((?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)'
            . '(?:\.(?:0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?'
            . '(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/D';

        if (preg_match($pattern, $value, $matches) !== 1) {
            throw new InvalidArgumentException('The version must conform to Semantic Versioning 2.0.0.');
        }

        foreach (array_slice($matches, 1, 3) as $component) {
            self::assertIntegerRange($component);
        }

        $preRelease = isset($matches[4]) && $matches[4] !== '' ? explode('.', $matches[4]) : [];

        return new self(
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            $preRelease,
            isset($matches[5]) && $matches[5] !== '' ? $matches[5] : null,
        );
    }

    public function major(): int
    {
        return $this->major;
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function patch(): int
    {
        return $this->patch;
    }

    public function isPreRelease(): bool
    {
        return $this->preRelease !== [];
    }

    public function compare(self $other): int
    {
        $leftCore = [$this->major, $this->minor, $this->patch];
        $rightCore = [$other->major, $other->minor, $other->patch];

        foreach ($leftCore as $index => $part) {
            $comparison = $part <=> $rightCore[$index];

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        if ($this->preRelease === $other->preRelease) {
            return 0;
        }

        if ($this->preRelease === []) {
            return 1;
        }

        if ($other->preRelease === []) {
            return -1;
        }

        $count = max(count($this->preRelease), count($other->preRelease));

        for ($index = 0; $index < $count; ++$index) {
            if (!isset($this->preRelease[$index])) {
                return -1;
            }

            if (!isset($other->preRelease[$index])) {
                return 1;
            }

            $comparison = self::compareIdentifier($this->preRelease[$index], $other->preRelease[$index]);

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    public function __toString(): string
    {
        $version = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);

        if ($this->preRelease !== []) {
            $version .= '-' . implode('.', $this->preRelease);
        }

        return $this->build === null ? $version : $version . '+' . $this->build;
    }

    private static function compareIdentifier(string $left, string $right): int
    {
        $leftNumeric = ctype_digit($left);
        $rightNumeric = ctype_digit($right);

        if ($leftNumeric && $rightNumeric) {
            $lengthComparison = strlen($left) <=> strlen($right);

            return $lengthComparison !== 0 ? $lengthComparison : $left <=> $right;
        }

        if ($leftNumeric !== $rightNumeric) {
            return $leftNumeric ? -1 : 1;
        }

        return $left <=> $right;
    }

    private static function assertIntegerRange(string $component): void
    {
        $maximum = (string) PHP_INT_MAX;

        if (strlen($component) > strlen($maximum)
            || (strlen($component) === strlen($maximum) && strcmp($component, $maximum) > 0)
        ) {
            throw new InvalidArgumentException('A semantic version component exceeds the platform integer range.');
        }
    }
}
