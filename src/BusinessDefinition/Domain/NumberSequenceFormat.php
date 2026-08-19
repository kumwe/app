<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;

/**
 * The declared shape of one `core.sequence` field: which counter it draws from and how the value reads.
 *
 * A business does not want an opaque integer on an invoice, it wants `INV-2026-000001`, and it wants the
 * next one to be `INV-2026-000002` with nothing missing in between. This value object holds the half of
 * that promise a definition can state: the tenancy scope and reset period that together pick the counter
 * row, and the prefix, padding and timezone that turn the counter's integer into the printed number. The
 * other half — that the integer is allocated exactly once per committed record — belongs to
 * `BusinessNumberSequenceAllocator`.
 *
 * The configuration is read straight off a published `FieldDefinition`, so every value is validated here
 * rather than trusted. `BusinessDefinitionValidator` builds one at publication time for exactly that
 * reason: a definition that reached storage with an unusable sequence declaration would only fail at the
 * moment a record is created, which is the worst possible time to discover it.
 *
 * @since  2.0.0
 */
final readonly class NumberSequenceFormat
{
    /**
     * Widest padding a counter may declare, chosen so the padded run stays inside a signed 64-bit value.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_PADDING = 12;

    /**
     * Longest literal prefix a number may carry, so the rendered value stays inside its column.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_PREFIX = 16;

    /**
     * Longest value `render()` can produce, and therefore the width a `core.sequence` column needs.
     *
     * The prefix, the widest period segment `NumberSequenceReset` emits plus its separator, and the widest
     * padded counter, added together. `BusinessDefinitionValidator` requires the declared column to be at
     * least this wide, so no allocated number can ever outgrow the column it has to be written into.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_LENGTH = self::MAXIMUM_PREFIX + 8 + self::MAXIMUM_PADDING;

    /**
     * Hold a validated sequence declaration.
     *
     * @param  NumberSequenceScope  $scope     Tenancy boundary the run is contiguous within.
     * @param  NumberSequenceReset  $reset     Calendar boundary the run restarts at.
     * @param  string               $prefix    Literal head of the rendered number, possibly empty.
     * @param  int                  $padding   Digits the counter is left-padded to with zeroes.
     * @param  DateTimeZone         $timezone  Zone the reset boundary and period segment are judged in.
     *
     * @since  2.0.0
     */
    private function __construct(
        public NumberSequenceScope $scope,
        public NumberSequenceReset $reset,
        public string $prefix,
        public int $padding,
        public DateTimeZone $timezone,
    ) {
    }

    /**
     * Read a `core.sequence` field's configuration into a usable declaration.
     *
     * Every key is optional and every default is the conservative one: a site-wide lifetime counter of six
     * digits with no prefix, judged in UTC. A key that is present but unusable is refused rather than
     * defaulted over, because silently ignoring `"reset": "quarterly"` would produce numbers that do not
     * mean what the definition says they mean.
     *
     * @param   array<string, scalar|list<scalar|null>|null>  $configuration  The field's declared
     *          configuration map, as `FieldDefinition` stores it.
     *
     * @return  self  The validated declaration this field allocates under.
     *
     * @throws  InvalidArgumentException  When a declared scope, reset, prefix, padding or timezone is not
     *          one this runtime can allocate and render with.
     *
     * @since   2.0.0
     */
    public static function fromConfiguration(array $configuration): self
    {
        $scope = NumberSequenceScope::tryFrom(self::text($configuration, 'scope', NumberSequenceScope::Site->value))
            ?? throw new InvalidArgumentException('A number sequence declares an unknown scope.');
        $reset = NumberSequenceReset::tryFrom(self::text($configuration, 'reset', NumberSequenceReset::Never->value))
            ?? throw new InvalidArgumentException('A number sequence declares an unknown reset period.');
        $prefix = self::text($configuration, 'prefix', '');
        if (preg_match('/^[A-Z0-9\/-]{0,' . self::MAXIMUM_PREFIX . '}$/D', $prefix) !== 1) {
            throw new InvalidArgumentException(
                'A number sequence prefix may only carry upper-case letters, digits, hyphens and slashes.',
            );
        }
        $padding = $configuration['padding'] ?? 6;
        if (!is_int($padding) || $padding < 1 || $padding > self::MAXIMUM_PADDING) {
            throw new InvalidArgumentException(sprintf(
                'A number sequence padding must be a whole number of 1 to %d digits.',
                self::MAXIMUM_PADDING,
            ));
        }

        return new self($scope, $reset, $prefix, $padding, self::zone($configuration));
    }

    /**
     * Name the counter row this format allocates from for a given record scope and instant.
     *
     * @param   ?string            $organizationIdentifier  Organization the record was scoped to, or null.
     * @param   DateTimeImmutable  $at                      Instant the allocation is being made at.
     *
     * @return  array{scope: string, period: string}  The two coordinates that, with the site, definition
     *          and field handle, identify exactly one counter.
     *
     * @throws  InvalidArgumentException  When a per-organization sequence has no organization to key on.
     *
     * @since   2.0.0
     */
    public function counter(?string $organizationIdentifier, DateTimeImmutable $at): array
    {
        return [
            'scope' => $this->scope->key($organizationIdentifier),
            'period' => $this->reset->key($at, $this->timezone),
        ];
    }

    /**
     * Turn one allocated counter value into the number a person reads on the document.
     *
     * @param   int     $value      Counter value the allocator reserved; always one or more.
     * @param   string  $periodKey  Period segment from `counter()`, empty for a lifetime run.
     *
     * @return  string  Prefix, period segment and zero-padded counter, joined with a hyphen where both
     *          the period segment and the digits are present.
     *
     * @throws  InvalidArgumentException  When the value is not positive, or has outgrown its padding so
     *          badly that the rendered number would exceed what the format can hold.
     *
     * @since   2.0.0
     */
    public function render(int $value, string $periodKey): string
    {
        if ($value < 1) {
            throw new InvalidArgumentException('An allocated number sequence value is always positive.');
        }
        $digits = str_pad((string) $value, $this->padding, '0', STR_PAD_LEFT);
        $rendered = $this->prefix . ($periodKey === '' ? '' : $periodKey . '-') . $digits;
        if (strlen($rendered) > self::MAXIMUM_LENGTH) {
            throw new InvalidArgumentException('An allocated number has outgrown the format that renders it.');
        }

        return $rendered;
    }

    /**
     * Read one configuration key as text, falling back to the declaration's default.
     *
     * @param   array<string, scalar|list<scalar|null>|null>  $configuration  Declared configuration map.
     * @param   string                                        $key            Key being read.
     * @param   string                                        $default        Value used when absent or null.
     *
     * @return  string  The declared text, or the default.
     *
     * @throws  InvalidArgumentException  When the key is present but is not a string.
     *
     * @since   2.0.0
     */
    private static function text(array $configuration, string $key, string $default): string
    {
        $value = $configuration[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('A number sequence %s must be declared as text.', $key));
        }

        return $value;
    }

    /**
     * Read the zone the reset boundary is judged in, defaulting to UTC.
     *
     * The zone is part of what the number means: a yearly counter in `Africa/Johannesburg` rolls over two
     * hours before the same counter in UTC would, and an invoice raised just after midnight local time on
     * 1 January must not be handed the previous year's run.
     *
     * @param   array<string, scalar|list<scalar|null>|null>  $configuration  Declared configuration map.
     *
     * @return  DateTimeZone  The declared zone, or UTC when none was declared.
     *
     * @throws  InvalidArgumentException  When the declared identifier is not one this runtime knows.
     *
     * @since   2.0.0
     */
    private static function zone(array $configuration): DateTimeZone
    {
        $identifier = self::text($configuration, 'timezone', 'UTC');

        try {
            return new DateTimeZone($identifier);
        } catch (Exception $exception) {
            throw new InvalidArgumentException('A number sequence declares an unknown timezone.', 0, $exception);
        }
    }
}
