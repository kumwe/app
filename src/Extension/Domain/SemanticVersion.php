<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * Version of an extension release, parsed under Semantic Versioning 2.0.0 and orderable.
 *
 * The constructor is private, so every instance came through `fromString()` and is known to be well
 * formed: three non-negative components inside the platform integer range, plus the optional
 * pre-release identifiers and build metadata the grammar allows. That is what lets the rest of the
 * extension code compare versions without re-validating them — `ExtensionRecord` and
 * `DoctrineExtensionManager` use `compare()` to decide whether a packaged manifest is really an
 * upgrade, and `VersionConstraint` evaluates every dependency range through it.
 *
 * @since  2.0.0
 */
final readonly class SemanticVersion implements Stringable
{
    /**
     * Store the components of a version string that has already been validated.
     *
     * @param  int           $major       Major component; a change here signals an incompatible release.
     * @param  int           $minor       Minor component; a change here signals backwards-compatible additions.
     * @param  int           $patch       Patch component; a change here signals a backwards-compatible fix.
     * @param  list<string>  $preRelease  Dot-separated pre-release identifiers, empty for a stable release.
     * @param  ?string       $build       Build metadata written after `+`, or null when none was given;
     *         it is rendered back but takes no part in ordering.
     *
     * @since  2.0.0
     */
    private function __construct(
        private int $major,
        private int $minor,
        private int $patch,
        private array $preRelease,
        private ?string $build,
    ) {
    }

    /**
     * Parse a version string into a comparable value.
     *
     * Surrounding whitespace is trimmed, and what remains must match the Semantic Versioning grammar
     * in full: a two-part version such as `2.0` is rejected rather than padded, and a component with a
     * leading zero is rejected rather than normalised.
     *
     * @param   string  $value  Version as written in a manifest or a registry row, for example
     *          `2.1.0-beta.1+build.5`.
     *
     * @return  self  Parsed version, keeping both its pre-release identifiers and its build metadata.
     *
     * @throws  InvalidArgumentException  When the value exceeds 128 characters, does not match the
     *          Semantic Versioning grammar, or carries a core component past the platform integer range.
     *
     * @since   2.0.0
     */
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
            $matches[5] ?? null,
        );
    }

    /**
     * Report the major component of the version core.
     *
     * @return  int  Non-negative; `VersionConstraint` reads it to place the upper bound of a `^` shorthand.
     *
     * @since   2.0.0
     */
    public function major(): int
    {
        return $this->major;
    }

    /**
     * Report the minor component of the version core.
     *
     * @return  int  Non-negative; the component a `~` shorthand increments to find its upper bound.
     *
     * @since   2.0.0
     */
    public function minor(): int
    {
        return $this->minor;
    }

    /**
     * Report the patch component of the version core.
     *
     * @return  int  Non-negative; the component a `^0.0.x` shorthand increments to find its upper bound.
     *
     * @since   2.0.0
     */
    public function patch(): int
    {
        return $this->patch;
    }

    /**
     * Report whether this version is a pre-release rather than a finished one.
     *
     * @return  bool  True when pre-release identifiers are present, which is exactly the condition that
     *          orders this version below the same core version without them.
     *
     * @since   2.0.0
     */
    public function isPreRelease(): bool
    {
        return $this->preRelease !== [];
    }

    /**
     * Order this version against another under the Semantic Versioning precedence rules.
     *
     * Major, minor and patch decide the outcome first. Where they tie, a version carrying pre-release
     * identifiers ranks below one that carries none, and otherwise the identifiers are walked pairwise
     * until one differs, with the shorter list ranking below a longer list that shares its prefix.
     * Build metadata is ignored entirely, so `2.0.0+one` and `2.0.0+two` have equal precedence.
     *
     * @param   self  $other  Version to order this one against.
     *
     * @return  int  Negative when this version precedes `$other`, zero when neither takes precedence,
     *          positive when this version follows it.
     *
     * @since   2.0.0
     */
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

    /**
     * Render the version back to the string form it was parsed from.
     *
     * @return  string  Dotted version core, pre-release identifiers after `-`, build metadata after `+`.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        $version = sprintf('%d.%d.%d', $this->major, $this->minor, $this->patch);

        if ($this->preRelease !== []) {
            $version .= '-' . implode('.', $this->preRelease);
        }

        return $this->build === null ? $version : $version . '+' . $this->build;
    }

    /**
     * Order one pair of pre-release identifiers taken from the same position in both versions.
     *
     * Two all-digit identifiers compare by digit count first and only then by the digits themselves,
     * which is a numeric ordering because the grammar forbids leading zeros. An all-digit identifier
     * always ranks below an alphanumeric one; two alphanumeric identifiers compare in ASCII order.
     *
     * @param   string  $left   Identifier from the version on the left of the comparison.
     * @param   string  $right  Identifier at the same position in the version on the right.
     *
     * @return  int  Negative when `$left` precedes `$right`, zero when they are identical, positive
     *          when `$left` follows it.
     *
     * @since   2.0.0
     */
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

    /**
     * Refuse a version core component that would not survive the cast to `int`.
     *
     * The grammar itself puts no ceiling on a component's digit count, so the digit string is measured
     * against `PHP_INT_MAX` before `fromString()` casts it and quietly loses the excess.
     *
     * @param   string  $component  Digit string matched as the major, minor or patch component.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the component is larger than `PHP_INT_MAX`.
     *
     * @since   2.0.0
     */
    private static function assertIntegerRange(string $component): void
    {
        $maximum = (string) PHP_INT_MAX;

        if (
            strlen($component) > strlen($maximum)
            || (strlen($component) === strlen($maximum) && strcmp($component, $maximum) > 0)
        ) {
            throw new InvalidArgumentException('A semantic version component exceeds the platform integer range.');
        }
    }
}
