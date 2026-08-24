<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\App\Studio\Domain\Contract\SchemaProfileRejected;
use Kumwe\App\Studio\Domain\Contract\SchemaPropertyProfile;
use stdClass;

/**
 * The documented manifest-5 compatibility adapter: lossless translations only, everything else named.
 *
 * A schema-5 block's bounded property map can partially be said in the canonical
 * `studio.profile/schema-property` vocabulary, and kumwe/app#104 draws the line exactly: the adapter
 * translates a mapping only when it is complete, deterministic and lossless, and anything else stays
 * diagnosable and unresolved so the author writes an explicit schema-6 declaration instead. There is
 * no silent widening, no defaulting and no data loss — a `reference` property, for example, is host
 * metadata by definition and has no canonical schema equivalent, so it is reported, never guessed.
 * Every produced schema is re-admitted through the complete profile before it is handed back, so the
 * adapter can never emit what admission would refuse.
 *
 * The adapter translates the property vocabulary, not whole documents: a canonical block-definition
 * document also demands identity, versioning, ownership and labelling members a schema-5 block never
 * declared, and inventing them would not be lossless. Those stay an explicit schema-6 authoring step.
 *
 * @since  2.0.0
 */
final class Manifest5CompositionAdapter
{
    /**
     * Translate one schema-5 property map into the canonical closed-root property schema.
     *
     * @param   CompositionPropertySchema  $properties  The frozen schema-5 bounded property map.
     *
     * @return  Manifest5AdapterResult  The canonical schema when every property translated, or the
     *          named reasons the map must be declared explicitly under schema 6.
     *
     * @since   2.0.0
     */
    public static function adaptPropertySchema(CompositionPropertySchema $properties): Manifest5AdapterResult
    {
        $unresolved = [];
        $translated = new stdClass();
        $required = [];
        foreach ($properties->toArray() as $name => $specification) {
            $name = (string) $name;
            if (!is_array($specification)) {
                $unresolved[] = sprintf('Property %s carries an unreadable specification.', $name);
                continue;
            }
            $member = self::translateProperty($name, $specification, $unresolved);
            if ($member === null) {
                continue;
            }
            $translated->{$name} = $member;
            if (($specification['required'] ?? false) === true) {
                $required[] = $name;
            }
        }
        if ($unresolved !== []) {
            return Manifest5AdapterResult::unresolved($unresolved);
        }

        $schema = new stdClass();
        $schema->type = 'object';
        $schema->additionalProperties = false;
        if (get_object_vars($translated) !== []) {
            $schema->properties = $translated;
        }
        if ($required !== []) {
            sort($required, SORT_STRING);
            $schema->required = $required;
        }
        try {
            SchemaPropertyProfile::admit($schema);
        } catch (SchemaProfileRejected $rejection) {
            return Manifest5AdapterResult::unresolved([sprintf(
                'The translated schema falls outside the profile (%s at %s).',
                $rejection->rejection,
                $rejection->schemaPath === '' ? 'the schema root' : $rejection->schemaPath,
            )]);
        }

        return Manifest5AdapterResult::translated($schema);
    }

    /**
     * Translate one property specification, or name exactly why it cannot be.
     *
     * @param   string                $name           Property name, used in diagnostics.
     * @param   array<string, mixed>  $specification  The schema-5 property specification.
     * @param   list<string>          $unresolved     Sink for the named untranslatable reasons.
     *
     * @return  stdClass|null  The canonical member schema, or null when the property stays unresolved.
     *
     * @since   2.0.0
     */
    private static function translateProperty(string $name, array $specification, array &$unresolved): ?stdClass
    {
        $member = new stdClass();
        $type = $specification['type'] ?? null;
        switch ($type) {
            case 'string':
            case 'text':
                $member->type = 'string';
                $length = $specification['maximum_length'] ?? null;
                if (is_int($length)) {
                    $member->maxLength = $length;
                }
                break;
            case 'integer':
            case 'number':
                $member->type = $type;
                foreach (['minimum' => 'minimum', 'maximum' => 'maximum'] as $from => $to) {
                    $bound = $specification[$from] ?? null;
                    if (is_int($bound) || is_float($bound)) {
                        $member->{$to} = $bound;
                    }
                }
                break;
            case 'boolean':
                $member->type = 'boolean';
                break;
            case 'choice':
                $values = $specification['values'] ?? null;
                if (!is_array($values) || !array_is_list($values) || $values === []) {
                    $unresolved[] = sprintf('Property %s declares a choice without its values.', $name);

                    return null;
                }
                $member->enum = $values;
                break;
            case 'reference':
                $unresolved[] = sprintf(
                    'Property %s is a host %s reference: host metadata has no canonical schema '
                        . 'equivalent and belongs in a schema-6 host binding.',
                    $name,
                    is_string($specification['kind'] ?? null) ? $specification['kind'] : 'unknown',
                );

                return null;
            default:
                $unresolved[] = sprintf(
                    'Property %s carries type %s, which has no lossless canonical mapping.',
                    $name,
                    var_export($type, true),
                );

                return null;
        }
        // `required` translates to the root's required list; any other member would be a guess.
        foreach (array_keys($specification) as $key) {
            if (!in_array($key, ['type', 'required', 'maximum_length', 'minimum', 'maximum', 'values', 'kind'], true)) {
                $unresolved[] = sprintf('Property %s carries untranslatable member %s.', $name, (string) $key);

                return null;
            }
        }

        return $member;
    }
}
