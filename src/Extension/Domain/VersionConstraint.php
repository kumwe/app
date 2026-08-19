<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * Range of versions a manifest declaration is willing to accept.
 *
 * Manifests state dependency ranges and platform compatibility as text; this parses that text once,
 * into the set of comparisons `accepts()` then evaluates against any `SemanticVersion`. Three forms
 * are understood: `*` or the empty string for "any version", a whitespace-separated list of `=`, `<`,
 * `<=`, `>` and `>=` comparisons that must all hold at once, and the `~` and `^` shorthands, which are
 * expanded at parse time into the equivalent lower and upper bound. The declared text is kept
 * alongside the comparisons, so a constraint renders back as the manifest wrote it, not as the
 * bounds it expanded to.
 *
 * @since  2.0.0
 */
final readonly class VersionConstraint implements Stringable
{
    /**
     * Comparisons a candidate must satisfy all of; empty for `*`, which therefore accepts anything.
     *
     * @var    list<array{operator: string, version: SemanticVersion}>
     * @since  2.0.0
     */
    private array $comparators;

    /**
     * Bind the declared text to the comparisons it was parsed into.
     *
     * @param string $expression Constraint exactly as declared, retained only for rendering.
     * @param  list<array{operator: string, version: SemanticVersion}>  $comparators  Bounds a candidate
     *         must clear, each pairing a comparison operator with the version to compare against.
     *
     * @since  2.0.0
     */
    private function __construct(private string $expression, array $comparators)
    {
        $this->comparators = $comparators;
    }

    /**
     * Parse a declared constraint expression.
     *
     * Whitespace around and between tokens carries no meaning, and an empty expression is read as `*`.
     * A `~` or `^` shorthand is recognised whole and expanded before tokenising ever happens; every
     * other expression is split on whitespace, and a token with no leading operator is an exact-version
     * comparison, so `2.0.0` means `=2.0.0`.
     *
     * @param   string  $expression  Constraint text from a manifest, such as `*`, `^2.1.0` or
     *          `>=2.0.0 <3.0.0`.
     *
     * @return  self  Constraint that holds only where every token of the expression holds.
     *
     * @throws  InvalidArgumentException  When the expression exceeds 255 characters, cannot be split
     *          into tokens, names a version the Semantic Versioning grammar rejects, or is a shorthand
     *          whose upper bound would run past the platform integer range.
     *
     * @since   2.0.0
     */
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

        $tokens = preg_split('/\s+/', $expression);

        if ($tokens === false) {
            throw new InvalidArgumentException('The version constraint could not be parsed.');
        }

        foreach ($tokens as $token) {
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

    /**
     * Decide whether a version falls inside this constraint.
     *
     * Every parsed comparison has to hold, so a constraint that parsed to none of them — `*` — accepts
     * everything. Ordering is `SemanticVersion::compare()`, which means build metadata is ignored and a
     * pre-release ranks below the finished version of the same core, so `2.0.0-beta` fails `>=2.0.0`.
     *
     * @param   SemanticVersion  $candidate  Version being offered, typically the one an installed or
     *          packaged extension reports.
     *
     * @return  bool  True only when the candidate clears every comparison in the constraint.
     *
     * @since   2.0.0
     */
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

    /**
     * Render the constraint as the manifest declared it.
     *
     * @return  string  The trimmed expression text, or `*` where the manifest left it empty; a
     *          shorthand renders as the shorthand, not as the pair of bounds it expanded to.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->expression;
    }

    /**
     * Expand a `~` or `^` shorthand into the pair of bounds it stands for.
     *
     * `~1.2.3` allows further patch releases and stops before `1.3.0`. `^` holds the leftmost non-zero
     * component fixed instead, so `^1.2.3` stops before `2.0.0`, `^0.2.3` before `0.3.0`, and `^0.0.3`
     * before `0.0.4`. The upper bound is exclusive on the finished version only: a pre-release of it,
     * such as `1.3.0-beta`, still ranks below the bound and is accepted.
     *
     * @param   string  $expression  Shorthand as declared, its leading `~` or `^` included.
     *
     * @return  self  Constraint carrying the `>=` lower bound and the `<` upper bound.
     *
     * @throws  InvalidArgumentException  When the text after the operator is not a valid semantic
     *          version, or the component the upper bound increments is already at the platform maximum.
     *
     * @since   2.0.0
     */
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

    /**
     * Raise a version component by one while building a shorthand's upper bound.
     *
     * @param   int  $component  Major, minor or patch value of the shorthand's lower bound.
     *
     * @return  int  The component plus one, the exclusive bound the constraint stops at.
     *
     * @throws  InvalidArgumentException  When the component is already `PHP_INT_MAX` and has no successor.
     *
     * @since   2.0.0
     */
    private static function increment(int $component): int
    {
        if ($component === PHP_INT_MAX) {
            throw new InvalidArgumentException('A shorthand constraint cannot increment the maximum integer value.');
        }

        return $component + 1;
    }
}
