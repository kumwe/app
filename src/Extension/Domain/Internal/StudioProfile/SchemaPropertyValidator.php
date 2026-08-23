<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain\Internal\StudioProfile;

use LogicException;
use SplObjectStorage;
use stdClass;

/**
 * An eval-free interpreting validator for an admitted Studio property schema.
 *
 * `SchemaPropertyProfile::admit()` has already proven the schema closed, bounded, local-reference-
 * only and non-recursive, so this interpreter walks the raw document at validation time: no code
 * generation, no shared state beyond the per-run diagnostic buffer. It mirrors the reference
 * interpreter's observable behaviour exactly — the verdict, the ordered set of distinct
 * diagnostics, sorted `required`/`dependentRequired` checks, code-unit-ordered object traversal,
 * and exact base-10 `multipleOf` comparison with no binary division and no epsilon — and is
 * proven by replaying the pinned schema-profile vector corpus. Verdicts for object-identified
 * instances are memoized per schema node and instance location within one run, so an acyclic
 * reference graph is evaluated once per actual instance location.
 *
 * @since  2.0.0
 */
final class SchemaPropertyValidator
{
    /**
     * Distinct failures of the most recent failing run, or null after a passing one.
     *
     * @var    list<SchemaInstanceDiagnostic>|null
     * @since  2.0.0
     */
    private ?array $diagnostics = null;

    /**
     * Per-run memo of subschema verdicts for identity-bearing instances.
     *
     * @var    SplObjectStorage<stdClass, array<string, array{0: bool, 1: list<SchemaInstanceDiagnostic>}>>
     * @since  2.0.0
     */
    private SplObjectStorage $memo;

    /**
     * Hold the admitted document and its pre-resolved reference targets.
     *
     * @param  stdClass                                   $root        Admitted schema document root.
     * @param  SplObjectStorage<stdClass, stdClass|bool>  $references  Resolved `$ref` target per
     *         referring schema node.
     *
     * @since  2.0.0
     */
    public function __construct(
        private readonly stdClass $root,
        private readonly SplObjectStorage $references,
    ) {
        $this->memo = new SplObjectStorage();
    }

    /**
     * Validate one decoded JSON instance against the admitted schema.
     *
     * @param   mixed  $instance  Decoded instance: null, bool, int, float, string, list array or
     *          `stdClass`.
     *
     * @return  bool  True when the instance satisfies the schema.
     *
     * @since   2.0.0
     */
    public function validate(mixed $instance): bool
    {
        $this->memo = new SplObjectStorage();
        $errors = [];
        /** @var SplObjectStorage<object, mixed> $active */
        $active = new SplObjectStorage();
        $valid = $this->subschema($this->root, $instance, '', $errors, $active);
        $diagnostics = self::uniqueDiagnostics($errors);
        if ($valid === ($diagnostics !== [])) {
            throw new LogicException('Schema validation verdict and diagnostics disagree.');
        }
        $this->diagnostics = $diagnostics === [] ? null : $diagnostics;

        return $valid;
    }

    /**
     * Report the distinct failures of the most recent failing run.
     *
     * @return  list<SchemaInstanceDiagnostic>|null  Ordered diagnostics, or null after a pass.
     *
     * @since   2.0.0
     */
    public function diagnostics(): ?array
    {
        return $this->diagnostics;
    }

    /**
     * Validate an instance against one subschema, memoizing identity-bearing verdicts.
     *
     * @param   stdClass|bool                    $schema    Subschema to apply.
     * @param   mixed                            $instance  Instance value at this location.
     * @param   string                           $path      Instance JSON Pointer.
     * @param   list<SchemaInstanceDiagnostic>   $errors    Failure sink, appended in evaluation order.
     * @param   SplObjectStorage<object, mixed>  $active    Schema nodes currently on the stack.
     *
     * @return  bool  The subschema verdict.
     *
     * @since   2.0.0
     */
    private function subschema(
        stdClass|bool $schema,
        mixed $instance,
        string $path,
        array &$errors,
        SplObjectStorage $active,
    ): bool {
        if (is_bool($schema)) {
            if (!$schema) {
                $errors[] = new SchemaInstanceDiagnostic($path, 'false', 'boolean schema is false');
            }

            return $schema;
        }
        $memoKey = self::instanceKey($instance);
        if ($memoKey !== null && isset($this->memo[$schema])) {
            $cached = $this->memo[$schema][$path . '|' . $memoKey] ?? null;
            if ($cached !== null) {
                foreach ($cached[1] as $diagnostic) {
                    $errors[] = $diagnostic;
                }

                return $cached[0];
            }
        }
        if ($active->contains($schema)) {
            throw new LogicException('Schema evaluation cycled without consuming instance input.');
        }
        $active->attach($schema);
        $firstNewError = count($errors);
        try {
            $valid = $this->node($schema, $instance, $path, $errors, $active);
        } finally {
            $active->detach($schema);
        }
        $diagnostics = self::uniqueDiagnostics(array_slice($errors, $firstNewError));
        if ($valid === ($diagnostics !== [])) {
            throw new LogicException('Subschema validation verdict and diagnostics disagree.');
        }
        if ($memoKey !== null) {
            $memoized = isset($this->memo[$schema]) ? $this->memo[$schema] : [];
            $memoized[$path . '|' . $memoKey] = [$valid, $diagnostics];
            $this->memo[$schema] = $memoized;
        }

        return $valid;
    }

    /**
     * Apply every keyword one schema node declares.
     *
     * @param   stdClass                         $schema    Schema node.
     * @param   mixed                            $instance  Instance value at this location.
     * @param   string                           $path      Instance JSON Pointer.
     * @param   list<SchemaInstanceDiagnostic>   $errors    Failure sink.
     * @param   SplObjectStorage<object, mixed>  $active    Schema nodes currently on the stack.
     *
     * @return  bool  The node verdict.
     *
     * @since   2.0.0
     */
    private function node(
        stdClass $schema,
        mixed $instance,
        string $path,
        array &$errors,
        SplObjectStorage $active,
    ): bool {
        $valid = true;
        $fail = function (string $keyword, string $message, ?string $at = null) use (&$valid, &$errors, $path): void {
            $valid = false;
            $errors[] = new SchemaInstanceDiagnostic($at ?? $path, $keyword, $message);
        };

        if (property_exists($schema, '$ref')) {
            $target = $this->references->contains($schema) ? $this->references[$schema] : null;
            if ($target === null) {
                throw new LogicException('Schema reference was not resolved at admission time.');
            }
            if (!$this->subschema($target, $instance, $path, $errors, $active)) {
                $valid = false;
            }
        }

        $type = $schema->type ?? null;
        if (is_string($type)) {
            if (!self::matchesType($type, $instance)) {
                $fail('type', 'must be ' . $type);
            }
        } elseif (is_array($type)) {
            $matched = false;
            foreach ($type as $name) {
                if (is_string($name) && self::matchesType($name, $instance)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $names = array_map(
                    static fn (mixed $name): string => is_string($name) ? $name : '',
                    $type,
                );
                $fail('type', 'must be ' . implode(',', $names));
            }
        }

        $enum = $schema->enum ?? null;
        if (is_array($enum)) {
            $matched = false;
            foreach ($enum as $member) {
                if (self::deepEqual($member, $instance)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $fail('enum', 'must be equal to one of the allowed values');
            }
        }
        if (property_exists($schema, 'const') && !self::deepEqual($schema->const, $instance)) {
            $fail('const', 'must be equal to constant');
        }

        $this->combinators($schema, $instance, $path, $errors, $active, $fail);

        if (is_string($instance)) {
            self::stringKeywords($schema, $instance, $fail);
        } elseif ((is_int($instance) || is_float($instance)) && is_finite((float) $instance)) {
            self::numberKeywords($schema, $instance, $fail);
        } elseif (is_array($instance)) {
            if (!$this->arrayKeywords($schema, array_values($instance), $path, $errors, $fail)) {
                $valid = false;
            }
        } elseif ($instance instanceof stdClass) {
            if (!$this->objectKeywords($schema, $instance, $path, $errors, $fail)) {
                $valid = false;
            }
        }

        return $valid;
    }

    /**
     * Apply the composition keywords, speculating with a scratch buffer where the profile demands it.
     *
     * @param   stdClass                                  $schema    Schema node.
     * @param   mixed                                     $instance  Instance value.
     * @param   string                                    $path      Instance JSON Pointer.
     * @param   list<SchemaInstanceDiagnostic>            $errors    Failure sink.
     * @param   SplObjectStorage<object, mixed>           $active    Schema nodes on the stack.
     * @param   callable(string, string, ?string=): void  $fail      Failure reporter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function combinators(
        stdClass $schema,
        mixed $instance,
        string $path,
        array &$errors,
        SplObjectStorage $active,
        callable $fail,
    ): void {
        $speculate = function (mixed $subschema) use ($instance, $path, $active): bool {
            $scratch = [];

            return $this->subschema(self::asSubschema($subschema), $instance, $path, $scratch, $active);
        };

        $allOf = $schema->allOf ?? null;
        if (is_array($allOf)) {
            foreach ($allOf as $member) {
                if (!$this->subschema(self::asSubschema($member), $instance, $path, $errors, $active)) {
                    $fail('allOf', 'must match all schemas in allOf');
                }
            }
        }
        $anyOf = $schema->anyOf ?? null;
        if (is_array($anyOf)) {
            $matched = false;
            foreach ($anyOf as $member) {
                if ($speculate($member)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $fail('anyOf', 'must match a schema in anyOf');
            }
        }
        $oneOf = $schema->oneOf ?? null;
        if (is_array($oneOf)) {
            $matches = 0;
            foreach ($oneOf as $member) {
                if ($speculate($member) && ++$matches > 1) {
                    break;
                }
            }
            if ($matches !== 1) {
                $fail('oneOf', 'must match exactly one schema in oneOf');
            }
        }
        if (property_exists($schema, 'not') && $speculate($schema->not)) {
            $fail('not', 'must NOT be valid');
        }
        if (property_exists($schema, 'if')) {
            $branch = $speculate($schema->if)
                ? ($schema->then ?? null)
                : ($schema->else ?? null);
            if (
                $branch !== null
                && !$this->subschema(self::asSubschema($branch), $instance, $path, $errors, $active)
            ) {
                $fail('if', 'must match the conditional schema');
            }
        }
    }

    /**
     * Apply the string keywords by code-point length.
     *
     * @param   stdClass                                  $schema    Schema node.
     * @param   string                                    $instance  String instance.
     * @param   callable(string, string, ?string=): void  $fail      Failure reporter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function stringKeywords(stdClass $schema, string $instance, callable $fail): void
    {
        $minLength = $schema->minLength ?? null;
        $maxLength = $schema->maxLength ?? null;
        if (is_int($minLength) || is_int($maxLength)) {
            $length = mb_strlen($instance, 'UTF-8');
            if (is_int($minLength) && $length < $minLength) {
                $fail('minLength', sprintf('must NOT have fewer than %d characters', $minLength));
            }
            if (is_int($maxLength) && $length > $maxLength) {
                $fail('maxLength', sprintf('must NOT have more than %d characters', $maxLength));
            }
        }
    }

    /**
     * Apply the number keywords, comparing `multipleOf` on exact base-10 coefficients.
     *
     * @param   stdClass                                  $schema    Schema node.
     * @param   int|float                                 $instance  Finite numeric instance.
     * @param   callable(string, string, ?string=): void  $fail      Failure reporter.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function numberKeywords(stdClass $schema, int|float $instance, callable $fail): void
    {
        $minimum = $schema->minimum ?? null;
        if ((is_int($minimum) || is_float($minimum)) && $instance < $minimum) {
            $fail('minimum', 'must be >= ' . CanonicalJson::encodeNumber($minimum));
        }
        $maximum = $schema->maximum ?? null;
        if ((is_int($maximum) || is_float($maximum)) && $instance > $maximum) {
            $fail('maximum', 'must be <= ' . CanonicalJson::encodeNumber($maximum));
        }
        $exclusiveMinimum = $schema->exclusiveMinimum ?? null;
        if ((is_int($exclusiveMinimum) || is_float($exclusiveMinimum)) && $instance <= $exclusiveMinimum) {
            $fail('exclusiveMinimum', 'must be > ' . CanonicalJson::encodeNumber($exclusiveMinimum));
        }
        $exclusiveMaximum = $schema->exclusiveMaximum ?? null;
        if ((is_int($exclusiveMaximum) || is_float($exclusiveMaximum)) && $instance >= $exclusiveMaximum) {
            $fail('exclusiveMaximum', 'must be < ' . CanonicalJson::encodeNumber($exclusiveMaximum));
        }
        $multipleOf = $schema->multipleOf ?? null;
        if (
            (is_int($multipleOf) || is_float($multipleOf))
            && !self::isCanonicalDecimalMultiple($instance, $multipleOf)
        ) {
            $fail('multipleOf', 'must be multiple of ' . CanonicalJson::encodeNumber($multipleOf));
        }
    }

    /**
     * Apply the array keywords, recursing per item with a fresh active set.
     *
     * @param   stdClass                                  $schema    Schema node.
     * @param   list<mixed>                               $instance  Array instance.
     * @param   string                                    $path      Instance JSON Pointer.
     * @param   list<SchemaInstanceDiagnostic>            $errors    Failure sink.
     * @param   callable(string, string, ?string=): void  $fail      Failure reporter.
     *
     * @return  bool  True when every item keyword held.
     *
     * @since   2.0.0
     */
    private function arrayKeywords(
        stdClass $schema,
        array $instance,
        string $path,
        array &$errors,
        callable $fail,
    ): bool {
        $valid = true;
        $child = function (mixed $subschema, int $index) use (&$valid, $instance, $path, &$errors): void {
            $fresh = new SplObjectStorage();
            if (
                !$this->subschema(
                    self::asSubschema($subschema),
                    $instance[$index],
                    $path . '/' . $index,
                    $errors,
                    $fresh,
                )
            ) {
                $valid = false;
            }
        };

        $prefixItems = $schema->prefixItems ?? null;
        $prefixLength = is_array($prefixItems) ? count($prefixItems) : 0;
        if (is_array($prefixItems)) {
            $bounded = min($prefixLength, count($instance));
            for ($index = 0; $index < $bounded; $index++) {
                $child($prefixItems[$index], $index);
            }
        }
        if (property_exists($schema, 'items') && count($instance) > $prefixLength) {
            $items = $schema->items;
            if ($items === false) {
                $fail('items', sprintf('must NOT have more than %d items', $prefixLength));
            } elseif ($items !== true) {
                for ($index = $prefixLength; $index < count($instance); $index++) {
                    $child($items, $index);
                }
            }
        }

        $minItems = $schema->minItems ?? null;
        if (is_int($minItems) && count($instance) < $minItems) {
            $fail('minItems', sprintf('must NOT have fewer than %d items', $minItems));
        }
        $maxItems = $schema->maxItems ?? null;
        if (is_int($maxItems) && count($instance) > $maxItems) {
            $fail('maxItems', sprintf('must NOT have more than %d items', $maxItems));
        }
        if (($schema->uniqueItems ?? null) === true) {
            $duplicate = self::findDuplicateIndexes($instance);
            if ($duplicate !== null) {
                $fail('uniqueItems', sprintf(
                    'must NOT have duplicate items (items ## %d and %d are identical)',
                    $duplicate[0],
                    $duplicate[1],
                ));
            }
        }

        return $valid;
    }

    /**
     * Apply the object keywords in code-unit member order with sorted name-array checks.
     *
     * @param   stdClass                                  $schema    Schema node.
     * @param   stdClass                                  $instance  Object instance.
     * @param   string                                    $path      Instance JSON Pointer.
     * @param   list<SchemaInstanceDiagnostic>            $errors    Failure sink.
     * @param   callable(string, string, ?string=): void  $fail      Failure reporter.
     *
     * @return  bool  True when every member keyword held.
     *
     * @since   2.0.0
     */
    private function objectKeywords(
        stdClass $schema,
        stdClass $instance,
        string $path,
        array &$errors,
        callable $fail,
    ): bool {
        $valid = true;
        $memberNames = array_map(strval(...), array_keys(get_object_vars($instance)));
        usort($memberNames, CanonicalJson::compareCodeUnits(...));

        $properties = $schema->properties ?? null;
        $properties = $properties instanceof stdClass ? $properties : null;
        if ($properties !== null) {
            foreach (self::sortedMembers($properties) as $name) {
                if (!property_exists($instance, $name)) {
                    continue;
                }
                $fresh = new SplObjectStorage();
                if (
                    !$this->subschema(
                        self::asSubschema($properties->{$name}),
                        $instance->{$name},
                        $path . '/' . self::escapeToken($name),
                        $errors,
                        $fresh,
                    )
                ) {
                    $valid = false;
                }
            }
        }

        $required = $schema->required ?? null;
        if (is_array($required)) {
            foreach (self::sortedStrings($required) as $name) {
                if (!property_exists($instance, $name)) {
                    $fail('required', sprintf("must have required property '%s'", $name));
                }
            }
        }

        if (property_exists($schema, 'additionalProperties')) {
            $additional = $schema->additionalProperties;
            foreach ($memberNames as $name) {
                if ($properties !== null && property_exists($properties, $name)) {
                    continue;
                }
                if ($additional === false) {
                    $fail('additionalProperties', 'must NOT have additional properties');
                } elseif ($additional !== true) {
                    $fresh = new SplObjectStorage();
                    if (
                        !$this->subschema(
                            self::asSubschema($additional),
                            $instance->{$name},
                            $path . '/' . self::escapeToken($name),
                            $errors,
                            $fresh,
                        )
                    ) {
                        $valid = false;
                    }
                }
            }
        }

        if (property_exists($schema, 'propertyNames')) {
            foreach ($memberNames as $name) {
                $scratch = [];
                $fresh = new SplObjectStorage();
                if (
                    !$this->subschema(
                        self::asSubschema($schema->propertyNames),
                        $name,
                        $path,
                        $scratch,
                        $fresh,
                    )
                ) {
                    $fail('propertyNames', sprintf("property name '%s' is invalid", $name));
                }
            }
        }

        $dependentRequired = $schema->dependentRequired ?? null;
        if ($dependentRequired instanceof stdClass) {
            foreach (self::sortedMembers($dependentRequired) as $trigger) {
                $dependents = $dependentRequired->{$trigger};
                if (!property_exists($instance, $trigger) || !is_array($dependents)) {
                    continue;
                }
                foreach (self::sortedStrings($dependents) as $dependent) {
                    if (!property_exists($instance, $dependent)) {
                        $fail('dependentRequired', sprintf(
                            'must have property %s when property %s is present',
                            $dependent,
                            $trigger,
                        ));
                    }
                }
            }
        }

        $minProperties = $schema->minProperties ?? null;
        if (is_int($minProperties) && count($memberNames) < $minProperties) {
            $fail('minProperties', sprintf('must NOT have fewer than %d properties', $minProperties));
        }
        $maxProperties = $schema->maxProperties ?? null;
        if (is_int($maxProperties) && count($memberNames) > $maxProperties) {
            $fail('maxProperties', sprintf('must NOT have more than %d properties', $maxProperties));
        }

        return $valid;
    }

    /**
     * Compare an instance and a divisor as exact base-10 coefficients.
     *
     * Both numbers are read from their canonical shortest encodings into a coefficient and a
     * base-10 exponent; the multiple test is then an integer-digit modulo with no floating-point
     * division and no epsilon, so `4.02` is a multiple of `0.01` while `4.021` is not.
     *
     * @param   int|float  $instance  Finite instance value.
     * @param   int|float  $divisor   Finite positive divisor.
     *
     * @return  bool  True when the instance is an exact decimal multiple.
     *
     * @since   2.0.0
     */
    private static function isCanonicalDecimalMultiple(int|float $instance, int|float $divisor): bool
    {
        [$valueDigits, $valueExponent] = self::canonicalDecimal($instance);
        [$divisorDigits, $divisorExponent] = self::canonicalDecimal($divisor);
        if ($valueDigits === '0') {
            return true;
        }
        $difference = $valueExponent - $divisorExponent;
        if ($difference >= 0) {
            return self::digitsModulo($valueDigits . str_repeat('0', $difference), $divisorDigits) === '0';
        }

        return self::digitsModulo($valueDigits, $divisorDigits . str_repeat('0', -$difference)) === '0';
    }

    /**
     * Split a number's canonical encoding into unsigned coefficient digits and a base-10 exponent.
     *
     * @param   int|float  $value  Finite number.
     *
     * @return  array{0: string, 1: int}  Trailing-zero-free digits and their exponent.
     *
     * @since   2.0.0
     */
    private static function canonicalDecimal(int|float $value): array
    {
        $encoded = CanonicalJson::encodeNumber($value);
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:e([+-]?\d+))?$/', $encoded, $parts) !== 1) {
            throw new LogicException('Canonical decimal conversion requires a finite number.');
        }
        $fraction = $parts[3] ?? '';
        $digits = ltrim($parts[2] . $fraction, '0');
        if ($digits === '') {
            return ['0', 0];
        }
        $exponent = (int) ($parts[4] ?? '0') - strlen($fraction);
        $stripped = rtrim($digits, '0');
        $exponent += strlen($digits) - strlen($stripped);

        return [$stripped, $exponent];
    }

    /**
     * Compute `dividend mod divisor` over decimal digit strings.
     *
     * @param   string  $dividend  Unsigned decimal digits.
     * @param   string  $divisor   Unsigned decimal digits, never zero.
     *
     * @return  string  The remainder's decimal digits, `'0'` when it divides exactly.
     *
     * @since   2.0.0
     */
    private static function digitsModulo(string $dividend, string $divisor): string
    {
        $remainder = '0';
        $length = strlen($dividend);
        for ($index = 0; $index < $length; $index++) {
            $remainder = ltrim($remainder . $dividend[$index], '0');
            if ($remainder === '') {
                $remainder = '0';
                continue;
            }
            while (self::digitsCompare($remainder, $divisor) >= 0) {
                $remainder = self::digitsSubtract($remainder, $divisor);
            }
        }

        return $remainder;
    }

    /**
     * Compare two unsigned decimal digit strings.
     *
     * @param   string  $left   Unsigned digits without leading zeros.
     * @param   string  $right  Unsigned digits without leading zeros.
     *
     * @return  int  Negative, zero or positive.
     *
     * @since   2.0.0
     */
    private static function digitsCompare(string $left, string $right): int
    {
        $byLength = strlen($left) <=> strlen($right);

        return $byLength !== 0 ? $byLength : strcmp($left, $right);
    }

    /**
     * Subtract one unsigned decimal digit string from a larger or equal one.
     *
     * @param   string  $minuend     Unsigned digits, at least as large as the subtrahend.
     * @param   string  $subtrahend  Unsigned digits.
     *
     * @return  string  The difference without leading zeros, `'0'` when equal.
     *
     * @since   2.0.0
     */
    private static function digitsSubtract(string $minuend, string $subtrahend): string
    {
        $subtrahend = str_pad($subtrahend, strlen($minuend), '0', STR_PAD_LEFT);
        $result = '';
        $borrow = 0;
        for ($index = strlen($minuend) - 1; $index >= 0; $index--) {
            $digit = (int) $minuend[$index] - (int) $subtrahend[$index] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit + $borrow * 10) . $result;
        }
        $result = ltrim($result, '0');

        return $result === '' ? '0' : $result;
    }

    /**
     * Deep JSON-value equality: numeric across int and float, order-insensitive for objects.
     *
     * @param   mixed  $left   First decoded value.
     * @param   mixed  $right  Second decoded value.
     *
     * @return  bool  True when the two values are the same JSON value.
     *
     * @since   2.0.0
     */
    private static function deepEqual(mixed $left, mixed $right): bool
    {
        if ((is_int($left) || is_float($left)) && (is_int($right) || is_float($right))) {
            return (float) $left === (float) $right;
        }
        if ($left === $right) {
            return true;
        }
        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $index => $value) {
                if (!array_key_exists($index, $right) || !self::deepEqual($value, $right[$index])) {
                    return false;
                }
            }

            return true;
        }
        if ($left instanceof stdClass && $right instanceof stdClass) {
            $leftMembers = get_object_vars($left);
            $rightMembers = get_object_vars($right);
            if (count($leftMembers) !== count($rightMembers)) {
                return false;
            }
            foreach ($leftMembers as $name => $value) {
                if (!array_key_exists($name, $rightMembers) || !self::deepEqual($value, $rightMembers[$name])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Say whether an instance matches one JSON Schema type name.
     *
     * @param   string  $name      Type name from the closed set.
     * @param   mixed   $instance  Decoded instance value.
     *
     * @return  bool  True on a match.
     *
     * @since   2.0.0
     */
    private static function matchesType(string $name, mixed $instance): bool
    {
        return match ($name) {
            'array' => is_array($instance),
            'boolean' => is_bool($instance),
            'integer' => is_int($instance)
                || (is_float($instance) && is_finite($instance) && floor($instance) === $instance),
            'null' => $instance === null,
            'number' => is_int($instance) || (is_float($instance) && is_finite($instance)),
            'object' => $instance instanceof stdClass,
            'string' => is_string($instance),
            default => false,
        };
    }

    /**
     * Find the first pair of deep-equal items in an array.
     *
     * @param   list<mixed>  $instance  Array instance.
     *
     * @return  array{0: int, 1: int}|null  The identical pair's indexes, or null.
     *
     * @since   2.0.0
     */
    private static function findDuplicateIndexes(array $instance): ?array
    {
        $count = count($instance);
        for ($second = 1; $second < $count; $second++) {
            for ($first = 0; $first < $second; $first++) {
                if (self::deepEqual($instance[$second], $instance[$first])) {
                    return [$first, $second];
                }
            }
        }

        return null;
    }

    /**
     * Collapse exact duplicate diagnostics while retaining order and every distinct failure.
     *
     * @param   list<SchemaInstanceDiagnostic>  $errors  Raw failures in evaluation order.
     *
     * @return  list<SchemaInstanceDiagnostic>  The ordered distinct set.
     *
     * @since   2.0.0
     */
    private static function uniqueDiagnostics(array $errors): array
    {
        $seen = [];
        $diagnostics = [];
        foreach ($errors as $error) {
            $key = $error->instancePath . "\u{1}" . $error->keyword . "\u{1}" . $error->message;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $diagnostics[] = $error;
        }

        return $diagnostics;
    }

    /**
     * Narrow an admitted subschema operand to its runtime type.
     *
     * @param   mixed  $value  Operand admission proved is a schema object or boolean.
     *
     * @return  stdClass|bool  The subschema.
     *
     * @since   2.0.0
     */
    private static function asSubschema(mixed $value): stdClass|bool
    {
        if (is_bool($value) || $value instanceof stdClass) {
            return $value;
        }

        throw new LogicException('An admitted subschema operand lost its shape.');
    }

    /**
     * Sort an object's member names by UTF-16 code unit.
     *
     * @param   stdClass  $value  Object whose members are listed.
     *
     * @return  list<string>  Sorted member names.
     *
     * @since   2.0.0
     */
    private static function sortedMembers(stdClass $value): array
    {
        $names = array_map(strval(...), array_keys(get_object_vars($value)));
        usort($names, CanonicalJson::compareCodeUnits(...));

        return $names;
    }

    /**
     * Keep only the strings of a decoded array, sorted by UTF-16 code unit.
     *
     * @param   array<int|string, mixed>  $values  Decoded array operand.
     *
     * @return  list<string>  The sorted strings.
     *
     * @since   2.0.0
     */
    private static function sortedStrings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }
        usort($strings, CanonicalJson::compareCodeUnits(...));

        return $strings;
    }

    /**
     * Escape one JSON Pointer token.
     *
     * @param   string  $token  Raw member name.
     *
     * @return  string  The token with `~` and `/` escaped.
     *
     * @since   2.0.0
     */
    private static function escapeToken(string $token): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $token);
    }

    /**
     * Derive a memo key for an instance, or null when the value carries no cheap identity.
     *
     * Objects memoize by identity exactly as the reference does; scalars memoize by type and
     * value. PHP arrays are value types with no identity, so array instances are re-evaluated —
     * deterministically, with identical diagnostics — instead of being memoized.
     *
     * @param   mixed  $instance  Decoded instance value.
     *
     * @return  string|null  Stable per-run key, or null to skip memoization.
     *
     * @since   2.0.0
     */
    private static function instanceKey(mixed $instance): ?string
    {
        if ($instance instanceof stdClass) {
            return 'o' . spl_object_id($instance);
        }
        if ($instance === null || is_bool($instance) || is_int($instance) || is_float($instance)) {
            return 's' . var_export($instance, true);
        }
        if (is_string($instance)) {
            return 't' . $instance;
        }

        return null;
    }
}
