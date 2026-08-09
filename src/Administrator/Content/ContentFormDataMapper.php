<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Content;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;

/**
 * Rebuilds a content entry's data object from the flat `field__` inputs an editor form submits.
 *
 * HTML forms are flat while a content type's schema describes a nested JSON object, so the editor
 * renders one input per leaf under a path-encoded name and this mapper walks the same schema to put
 * the values back where they belong. It is the read half of `ContentFormPresenter`: one schema
 * produces the inputs there and consumes them here, so the two must agree on the naming scheme.
 * Values are coerced to the type the schema declares, and an optional field that arrived empty is
 * left out of the result rather than written as a blank, so a partial form cannot erase stored data.
 *
 * @since  2.0.0
 */
final readonly class ContentFormDataMapper
{
    /**
     * Map a submitted request body onto the data object the content type's schema describes.
     *
     * @param   ContentTypeDefinition    $definition  Content type whose schema decides what is read.
     * @param   array<array-key, mixed>  $body        Parsed request body holding the `field__` inputs.
     *
     * @return  array<string, mixed>  Entry data keyed by schema property, nested to match the schema.
     *
     * @throws  InvalidArgumentException  When a submitted value does not parse as the type its schema
     *          declares.
     *
     * @since   2.0.0
     */
    public function map(ContentTypeDefinition $definition, array $body): array
    {
        [$present, $value] = $this->mapObject($definition->schema(), $body, []);
        if (!$present) {
            return [];
        }

        return $value;
    }

    /**
     * Report whether a request body came from a schema-generated editor form.
     *
     * The administrator still accepts the older hand-written content form, so the create and update
     * handlers use this to decide whether to map through the content type schema at all.
     *
     * @param   array<array-key, mixed>  $body  Parsed request body to inspect.
     *
     * @return  bool  True when at least one `field__` input is present.
     *
     * @since   2.0.0
     */
    public function containsGeneratedFields(array $body): bool
    {
        foreach (array_keys($body) as $key) {
            if (is_string($key) && str_starts_with($key, 'field__')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Walk one object level of the schema and collect the values its properties resolve to.
     *
     * Recursion carries the property path so a nested leaf reads the `field__parent__child` name the
     * presenter rendered. A nested object survives only when one of its children arrived or the level
     * above marks it required, which is what keeps empty branches out of the stored document.
     *
     * @param   array<string, mixed>     $schema  Object schema level, holding `properties` and `required`.
     * @param   array<array-key, mixed>  $body    Parsed request body, read for every leaf below here.
     * @param   list<string>             $path    Property names walked so far; empty at the root.
     *
     * @return  array{bool, array<string, mixed>}  Whether anything was found, and what was collected.
     *
     * @throws  InvalidArgumentException  When a submitted value does not parse as the type its schema
     *          declares.
     *
     * @since   2.0.0
     */
    private function mapObject(array $schema, array $body, array $path): array
    {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || array_is_list($properties) || !is_array($required)) {
            return [false, []];
        }

        $result = [];
        $present = $path === [];
        foreach ($properties as $key => $fieldSchema) {
            if (!is_string($key) || !is_array($fieldSchema) || array_is_list($fieldSchema)) {
                continue;
            }
            /** @var array<string, mixed> $fieldSchema */
            $fieldPath = [...$path, $key];
            $isRequired = in_array($key, $required, true);
            $type = is_string($fieldSchema['type'] ?? null) ? $fieldSchema['type'] : 'string';
            if ($type === 'object') {
                [$childPresent, $child] = $this->mapObject($fieldSchema, $body, $fieldPath);
                if ($childPresent || $isRequired) {
                    $result[$key] = $child;
                    $present = true;
                }
                continue;
            }

            $name = 'field__' . implode('__', $fieldPath);
            $raw = $body[$name] ?? null;
            [$valuePresent, $value] = $this->mapValue($fieldSchema, $raw, $isRequired, $name);
            if ($valuePresent) {
                $result[$key] = $value;
                $present = true;
            }
        }

        return [$present, $result];
    }

    /**
     * Coerce one submitted input into the value its leaf schema declares.
     *
     * Booleans read presence rather than content, an array field splits its textarea on line breaks
     * and maps each non-blank line through this method again, and a `date-time` string is normalised
     * to RFC 3339. A missing optional value reports itself absent so the caller drops the property.
     *
     * @param   array<string, mixed>  $schema    Leaf schema giving the target type, format and default.
     * @param   mixed                 $raw       Submitted value, or null when the input was not sent.
     * @param   bool                  $required  Whether the level above lists this property required.
     * @param   string                $name      Input name, used only to name the field in errors.
     *
     * @return  array{bool, mixed}  Whether the value should be written, and the coerced value.
     *
     * @throws  InvalidArgumentException  When the value is not the whole number, number, or parseable
     *          date and time that the schema's declared type requires.
     *
     * @since   2.0.0
     */
    private function mapValue(array $schema, mixed $raw, bool $required, string $name): array
    {
        $type = is_string($schema['type'] ?? null) ? $schema['type'] : 'string';
        if ($type === 'boolean') {
            return [$required || $raw !== null, in_array($raw, ['1', 'true', 'on'], true)];
        }
        if (!is_string($raw)) {
            if (array_key_exists('default', $schema)) {
                return [true, $schema['default']];
            }
            return [$required, $type === 'array' ? [] : ''];
        }

        if ($type === 'array') {
            $splitLines = preg_split('/\R/u', $raw);
            $lines = $splitLines === false ? [] : $splitLines;
            $items = [];
            $itemSchema = $this->associativeArray($schema['items'] ?? null, ['type' => 'string']);
            foreach ($lines as $index => $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                [, $items[]] = $this->mapValue($itemSchema, $line, true, $name . '[' . $index . ']');
            }
            return [$required || $items !== [], $items];
        }

        if ($raw === '' && !$required) {
            return [false, null];
        }
        if ($type === 'integer') {
            if (preg_match('/^-?[0-9]+$/D', $raw) !== 1) {
                throw new InvalidArgumentException(sprintf('The %s field must contain a whole number.', $name));
            }
            return [true, (int) $raw];
        }
        if ($type === 'number') {
            if (!is_numeric($raw) || !is_finite((float) $raw)) {
                throw new InvalidArgumentException(sprintf('The %s field must contain a number.', $name));
            }
            return [true, (float) $raw];
        }
        if (($schema['format'] ?? null) === 'date-time' && $raw !== '') {
            try {
                return [true, (new DateTimeImmutable($raw))->format(DATE_ATOM)];
            } catch (\Exception $exception) {
                throw new InvalidArgumentException(sprintf(
                    'The %s field must contain a valid date and time.',
                    $name,
                ), 0, $exception);
            }
        }

        return [true, $raw];
    }

    /**
     * Narrow an untrusted schema fragment to a string-keyed map, falling back when it is not one.
     *
     * @param   mixed                 $value     Schema fragment taken straight from a stored definition.
     * @param   array<string, mixed>  $fallback  Map to use when the fragment is missing or is a list.
     *
     * @return  array<string, mixed>  The fragment with non-string keys dropped, or the fallback.
     *
     * @since   2.0.0
     */
    private function associativeArray(mixed $value, array $fallback): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return $fallback;
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
