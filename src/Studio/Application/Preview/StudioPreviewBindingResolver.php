<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use stdClass;

/**
 * Closed evaluator for the safe binding sources AP-6 can resolve without taking Content authority.
 *
 * Entry fields and registered context values come only from {@see StudioPreviewBindingValues}; static
 * values are already part of the schema-admitted Blueprint. Resource/query sources and contributed
 * transforms remain unresolved until a separately authorized host registry implements them.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewBindingResolver
{
    /**
     * Resolve the canonical `value` port used by the core App field block set.
     *
     * @param   stdClass                    $node    Schema-admitted Blueprint node.
     * @param   StudioPreviewBindingValues  $values  Trusted host-owned value namespaces.
     *
     * @return  StudioPreviewBindingResult  Plain JSON value, hidden result, or closed unresolved result.
     *
     * @since   2.0.0
     */
    public function resolve(stdClass $node, StudioPreviewBindingValues $values): StudioPreviewBindingResult
    {
        $bindings = $node->bindings ?? null;
        $binding = $bindings instanceof stdClass ? $bindings->value ?? null : null;
        if (!$binding instanceof stdClass) {
            return StudioPreviewBindingResult::unavailable();
        }
        $transforms = $binding->transforms ?? null;
        if (!is_array($transforms) || $transforms !== []) {
            return self::failed($binding);
        }
        $source = $binding->source ?? null;
        if (!$source instanceof stdClass || !is_string($source->kind ?? null)) {
            return self::failed($binding);
        }
        [$found, $value] = match ($source->kind) {
            'entry-field' => self::entryField($source, $values->entry()),
            'context-value' => self::contextValue($source, $values->context()),
            'static-value' => property_exists($source, 'value')
                ? [true, $source->value]
                : [false, null],
            default => [false, null],
        };
        if (!$found) {
            return self::failed($binding);
        }
        if ($value === null) {
            return self::null($binding);
        }

        return new StudioPreviewBindingResult(true, false, $value);
    }

    /**
     * Walk a projected entry-field path without coercing objects or arrays.
     *
     * @param   stdClass  $source  Schema-admitted entry-field source.
     * @param   stdClass  $entry   Authorized projected entry values.
     *
     * @return  array{bool, mixed}  Presence and exact JSON value.
     *
     * @since   2.0.0
     */
    private static function entryField(stdClass $source, stdClass $entry): array
    {
        $path = $source->fieldPath ?? null;
        if (!is_array($path) || $path === []) {
            return [false, null];
        }
        $value = $entry;
        foreach ($path as $member) {
            if (!is_string($member)) {
                return [false, null];
            }
            if ($value instanceof stdClass && property_exists($value, $member)) {
                $value = $value->{$member};
                continue;
            }
            if (is_array($value) && ctype_digit($member) && array_key_exists((int) $member, $value)) {
                $value = $value[(int) $member];
                continue;
            }

            return [false, null];
        }

        return [true, $value];
    }

    /**
     * Resolve one explicitly registered qualified context key.
     *
     * @param   stdClass  $source   Schema-admitted context-value source.
     * @param   stdClass  $context  Registered host context values.
     *
     * @return  array{bool, mixed}  Presence and exact JSON value.
     *
     * @since   2.0.0
     */
    private static function contextValue(stdClass $source, stdClass $context): array
    {
        $key = $source->key ?? null;
        if (!is_string($key) || !property_exists($context, $key)) {
            return [false, null];
        }

        return [true, $context->{$key}];
    }

    /**
     * Apply the binding's explicit failure policy without disclosing a refused source.
     *
     * @param   stdClass  $binding  Schema-admitted binding and its explicit error policy.
     *
     * @return  StudioPreviewBindingResult  Fallback, hidden, or unresolved result.
     *
     * @since   2.0.0
     */
    private static function failed(stdClass $binding): StudioPreviewBindingResult
    {
        return match ($binding->onError ?? null) {
            'fallback' => property_exists($binding, 'fallback')
                ? new StudioPreviewBindingResult(true, false, $binding->fallback)
                : StudioPreviewBindingResult::unavailable(),
            'hide' => StudioPreviewBindingResult::hidden(),
            default => StudioPreviewBindingResult::unavailable(),
        };
    }

    /**
     * Apply the binding's explicit null policy.
     *
     * @param   stdClass  $binding  Schema-admitted binding and its explicit null policy.
     *
     * @return  StudioPreviewBindingResult  Empty, fallback, hidden, or unresolved result.
     *
     * @since   2.0.0
     */
    private static function null(stdClass $binding): StudioPreviewBindingResult
    {
        return match ($binding->onNull ?? null) {
            'empty' => new StudioPreviewBindingResult(true, false, ''),
            'fallback' => property_exists($binding, 'fallback')
                ? new StudioPreviewBindingResult(true, false, $binding->fallback)
                : StudioPreviewBindingResult::unavailable(),
            'hide' => StudioPreviewBindingResult::hidden(),
            default => StudioPreviewBindingResult::unavailable(),
        };
    }
}
