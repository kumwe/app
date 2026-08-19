<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessIntegration\Domain\EventSensitivity;
use Kumwe\App\BusinessIntegration\Domain\IntegrationContract;

/**
 * Immutable contract for a derived projection that can be discarded and rebuilt from versioned events.
 *
 * @since  2.0.0
 */
final readonly class ProjectionDefinition implements IntegrationContract
{
    /**
     * Sources validated for deterministic projection rebuilds.
     *
     * @var    non-empty-list<ProjectionSourceDefinition>
     * @since  2.0.0
     */
    public array $sources;

    /**
     * Fields validated for deterministic projection rebuilds.
     *
     * @var    non-empty-list<ProjectionFieldDefinition>
     * @since  2.0.0
     */
    public array $fields;

    /**
     * Key fields validated for deterministic projection rebuilds.
     *
     * @var    non-empty-list<string>
     * @since  2.0.0
     */
    public array $keyFields;

    /**
     * Assemble one reproducible projection declaration.
     *
     * @param   string                                      $id                  Namespaced contribution identifier.
     * @param   int                                         $version             Positive builder contract version.
     * @param   string                                      $handlerVersion      Executable builder revision token.
     * @param   EventSensitivity                            $sensitivityCeiling  Most sensitive event accepted.
     * @param   non-empty-list<ProjectionSourceDefinition>  $sources             Versioned event inputs, at most 16.
     * @param   non-empty-list<ProjectionFieldDefinition>   $fields              Typed derived fields, at most 64.
     * @param   non-empty-list<string>                      $keyFields           Field handles forming the row key.
     * @param   int                                         $rebuildBatchSize    Deterministic replay batch, 1 to 1000.
     *
     * @throws  InvalidArgumentException  When a bound, identifier or key-field reference is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $id,
        public int $version,
        public string $handlerVersion,
        public EventSensitivity $sensitivityCeiling,
        array $sources,
        array $fields,
        array $keyFields,
        public int $rebuildBatchSize = 200,
    ) {
        ReportDefinitionGuard::identifier($id, 'projection identifier');
        if (
            $version < 1 || $sources === [] || count($sources) > 16 || $fields === [] || count($fields) > 64
            || $keyFields === [] || count($keyFields) > 8 || $rebuildBatchSize < 1 || $rebuildBatchSize > 1000
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $handlerVersion) !== 1
        ) {
            throw new InvalidArgumentException('A projection definition exceeds a declared bound.');
        }
        $sourceTypes = [];
        foreach ($sources as $source) {
            if (!$source instanceof ProjectionSourceDefinition || isset($sourceTypes[$source->eventType])) {
                throw new InvalidArgumentException('A projection source is invalid or duplicated.');
            }
            $sourceTypes[$source->eventType] = true;
        }
        $fieldNames = [];
        foreach ($fields as $field) {
            if (!$field instanceof ProjectionFieldDefinition || isset($fieldNames[$field->name])) {
                throw new InvalidArgumentException('A projection field is invalid or duplicated.');
            }
            $fieldNames[$field->name] = true;
        }
        if (!array_is_list($keyFields) || count(array_unique($keyFields)) !== count($keyFields)) {
            throw new InvalidArgumentException('Projection key fields must be a unique list.');
        }
        foreach ($keyFields as $keyField) {
            if (!is_string($keyField) || !isset($fieldNames[$keyField])) {
                throw new InvalidArgumentException('A projection key references an undeclared field.');
            }
        }
        $this->sources = array_values($sources);
        $this->fields = array_values($fields);
        $this->keyFields = $keyFields;
    }

    /**
     * Return the stable contribution identifier.
     *
     * @return  string  Namespaced projection handle.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->id;
    }

    /**
     * Export every rebuild-relevant choice in deterministic manifest shape.
     *
     * @return  array<string, mixed>  Canonically encodable projection document.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->id,
            'version' => $this->version,
            'handler_version' => $this->handlerVersion,
            'rebuildable' => true,
            'sensitivity_ceiling' => $this->sensitivityCeiling->value,
            'sources' => array_map(static fn (ProjectionSourceDefinition $source): array => [
                'event_type' => $source->eventType,
                'schema_versions' => $source->schemaVersions,
            ], $this->sources),
            'fields' => array_map(static fn (ProjectionFieldDefinition $field): array => [
                'name' => $field->name,
                'type' => $field->type->value,
                'nullable' => $field->nullable,
            ], $this->fields),
            'key_fields' => $this->keyFields,
            'rebuild_batch_size' => $this->rebuildBatchSize,
        ];
    }

    /**
     * Rebuild a projection from its deterministic manifest document.
     *
     * @param   array<string, mixed>  $document  Exact output of `toArray()` from a trusted manifest parser.
     *
     * @return  self  Validated immutable projection definition.
     *
     * @throws  InvalidArgumentException  When the document has an invalid scalar or collection shape.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $document): self
    {
        self::keys($document, [
            'identifier', 'version', 'handler_version', 'rebuildable', 'sensitivity_ceiling', 'sources',
            'fields', 'key_fields', 'rebuild_batch_size',
        ]);
        $id = $document['identifier'] ?? null;
        $version = $document['version'] ?? null;
        $sources = $document['sources'] ?? null;
        $fields = $document['fields'] ?? null;
        $keyFields = $document['key_fields'] ?? null;
        $batchSize = $document['rebuild_batch_size'] ?? null;
        $handlerVersion = $document['handler_version'] ?? null;
        $rebuildable = $document['rebuildable'] ?? null;
        $sensitivity = $document['sensitivity_ceiling'] ?? null;
        if (
            !is_string($id) || !is_int($version) || !is_array($sources) || !array_is_list($sources)
            || !is_array($fields) || !array_is_list($fields) || !is_array($keyFields)
            || !array_is_list($keyFields) || !is_int($batchSize) || !is_string($handlerVersion)
            || $rebuildable !== true || !is_string($sensitivity)
        ) {
            throw new InvalidArgumentException('A projection definition document shape is invalid.');
        }
        try {
            $parsedSources = array_map(static function (mixed $item): ProjectionSourceDefinition {
                $item = self::object($item, 'source');
                self::keys($item, ['event_type', 'schema_versions']);
                $eventType = $item['event_type'] ?? null;
                $versions = $item['schema_versions'] ?? null;
                if (!is_string($eventType) || !is_array($versions) || !array_is_list($versions) || $versions === []) {
                    throw new InvalidArgumentException('A projection source document is invalid.');
                }
                $parsedVersions = [];
                foreach ($versions as $schemaVersion) {
                    if (!is_int($schemaVersion)) {
                        throw new InvalidArgumentException('A projection schema-version document is invalid.');
                    }
                    $parsedVersions[] = $schemaVersion;
                }

                return new ProjectionSourceDefinition($eventType, $parsedVersions);
            }, $sources);
            $parsedFields = array_map(static function (mixed $item): ProjectionFieldDefinition {
                $item = self::object($item, 'field');
                self::keys($item, ['name', 'type', 'nullable']);
                $name = $item['name'] ?? null;
                $type = $item['type'] ?? null;
                $nullable = $item['nullable'] ?? null;
                if (!is_string($name) || !is_string($type) || !is_bool($nullable)) {
                    throw new InvalidArgumentException('A projection field document is invalid.');
                }

                return new ProjectionFieldDefinition($name, ReportValueType::from($type), $nullable);
            }, $fields);
            $parsedKeyFields = [];
            foreach ($keyFields as $keyField) {
                if (!is_string($keyField)) {
                    throw new InvalidArgumentException('A projection key-field document is invalid.');
                }
                $parsedKeyFields[] = $keyField;
            }
            if ($parsedSources === [] || $parsedFields === [] || $parsedKeyFields === []) {
                throw new InvalidArgumentException('A projection definition requires sources, fields, and key fields.');
            }

            return new self(
                $id,
                $version,
                $handlerVersion,
                EventSensitivity::from($sensitivity),
                $parsedSources,
                $parsedFields,
                $parsedKeyFields,
                $batchSize,
            );
        } catch (\ValueError | \TypeError $exception) {
            throw new InvalidArgumentException('A projection definition document has an invalid value.', 0, $exception);
        }
    }

    /**
     * Fingerprint the exact rebuild contract.
     *
     * @return  string  Lowercase SHA-256 checksum.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return CanonicalDefinitionJson::checksum($this->toArray());
    }

    /**
     * Require an exact document key set so manifest typos cannot be silently discarded.
     *
     * @param   array<string, mixed>  $document  Object being parsed.
     * @param   list<string>          $expected  Complete accepted key set.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When any key is missing or unknown.
     *
     * @since   2.0.0
     */
    private static function keys(array $document, array $expected): void
    {
        $actual = array_keys($document);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('A projection definition has missing or unknown keys.');
        }
    }

    /**
     * Normalize one decoded JSON object while rejecting integer keys and list-shaped values.
     *
     * @param   mixed   $value  Candidate decoded object.
     * @param   string  $label  Stable member label used in validation errors.
     *
     * @return  array<string, mixed>  Validated object members.
     *
     * @since   2.0.0
     */
    private static function object(mixed $value, string $label): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('A projection %s document is invalid.', $label));
        }
        $object = [];
        foreach ($value as $key => $member) {
            if (!is_string($key)) {
                throw new InvalidArgumentException(sprintf('A projection %s document is invalid.', $label));
            }
            $object[$key] = $member;
        }

        return $object;
    }
}
