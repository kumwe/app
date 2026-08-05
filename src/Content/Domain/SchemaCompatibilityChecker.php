<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

final class SchemaCompatibilityChecker
{
    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return list<string>
     */
    public function breakingChanges(array $before, array $after): array
    {
        $changes = [];
        $beforeProperties = is_array($before['properties'] ?? null) ? $before['properties'] : [];
        $afterProperties = is_array($after['properties'] ?? null) ? $after['properties'] : [];
        $beforeRequired = is_array($before['required'] ?? null) ? $before['required'] : [];
        $afterRequired = is_array($after['required'] ?? null) ? $after['required'] : [];
        foreach ($beforeProperties as $key => $definition) {
            if (!array_key_exists($key, $afterProperties)) {
                $changes[] = 'removed field ' . (string) $key;
                continue;
            }
            $oldType = is_array($definition) ? ($definition['type'] ?? null) : null;
            $new = $afterProperties[$key];
            $newType = is_array($new) ? ($new['type'] ?? null) : null;
            if ($oldType !== $newType) {
                $changes[] = 'changed type of ' . (string) $key;
            }
            if (!is_array($definition) || !is_array($new)) {
                continue;
            }
            $oldEnum = $definition['enum'] ?? null;
            $newEnum = $new['enum'] ?? null;
            if (is_array($newEnum)) {
                $narrowed = !is_array($oldEnum);
                foreach (is_array($oldEnum) ? $oldEnum : [] as $oldValue) {
                    $narrowed = $narrowed || !in_array($oldValue, $newEnum, true);
                }
                if ($narrowed) {
                    $changes[] = 'narrowed enum of ' . (string) $key;
                }
            }
            if (isset($new['pattern']) && ($definition['pattern'] ?? null) !== $new['pattern']) {
                $changes[] = 'changed pattern of ' . (string) $key;
            }
            foreach (['minimum', 'minLength', 'minItems'] as $minimum) {
                $newMinimum = $new[$minimum] ?? null;
                $oldMinimum = $definition[$minimum] ?? null;
                if (
                    (is_int($newMinimum) || is_float($newMinimum))
                    && (!(is_int($oldMinimum) || is_float($oldMinimum)) || $newMinimum > $oldMinimum)
                ) {
                    $changes[] = 'raised ' . $minimum . ' of ' . (string) $key;
                }
            }
            foreach (['maximum', 'maxLength', 'maxItems'] as $maximum) {
                $newMaximum = $new[$maximum] ?? null;
                $oldMaximum = $definition[$maximum] ?? null;
                if (
                    (is_int($newMaximum) || is_float($newMaximum))
                    && (!(is_int($oldMaximum) || is_float($oldMaximum)) || $newMaximum < $oldMaximum)
                ) {
                    $changes[] = 'lowered ' . $maximum . ' of ' . (string) $key;
                }
            }
        }
        foreach ($afterRequired as $key) {
            if (is_string($key) && !in_array($key, $beforeRequired, true)) {
                $changes[] = 'made field required ' . $key;
            }
        }
        if (
            ($before['additionalProperties'] ?? true) !== false
            && ($after['additionalProperties'] ?? true) === false
        ) {
            $changes[] = 'disallowed additional fields';
        }
        sort($changes, SORT_STRING);
        return $changes;
    }
}
