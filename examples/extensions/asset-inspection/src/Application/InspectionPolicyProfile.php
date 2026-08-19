<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection\Application;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyComparison;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyComparisonOperator;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyPredicate;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyValueType;
use KumweExample\AssetInspection\Definitions;

/**
 * Parses the signed operator-applied row and field policy profile shipped with the proof component.
 *
 * Business-record policies are site-operator authority and deliberately are not an extension contribution:
 * a provider cannot grant itself record access. The package instead signs this closed profile so deployment
 * acceptance can apply its requests through the normal business-security administration service after the
 * definitions are active. The contributed pages execute the same typed predicate and disclosure plan.
 *
 * @since  2.0.0
 */
final readonly class InspectionPolicyProfile
{
    /** Maximum accepted profile size in bytes. @since 2.0.0 */
    private const int MAXIMUM_BYTES = 65_536;

    /** Stable signed profile grammar. @since 2.0.0 */
    private const string FORMAT = 'kumwe-asset-inspection-policy-profile-v1';

    /**
     * Validated administration requests in deterministic declaration order.
     *
     * @var    list<array{
     *             policy_code: string,
     *             operation: string,
     *             effect: string,
     *             predicate_type: string,
     *             field: string,
     *             operator: string,
     *             value_type: string,
     *             value: string,
     *             priority: int
     *         }>
     * @since  2.0.0
     */
    private array $requests;

    /**
     * Hold a completely validated profile.
     *
     * @param  RecordPolicySet      $records   Typed default-deny row predicate.
     * @param  FieldDisclosurePlan  $fields    Explicit per-use safe field allowlists.
     * @param  list<array{
     *             policy_code: string,
     *             operation: string,
     *             effect: string,
     *             predicate_type: string,
     *             field: string,
     *             operator: string,
     *             value_type: string,
     *             value: string,
     *             priority: int
     *         }> $requests                           Operator administration requests.
     * @param  string               $checksum  Canonical signed-profile checksum.
     *
     * @since  2.0.0
     */
    private function __construct(
        private RecordPolicySet $records,
        private FieldDisclosurePlan $fields,
        array $requests,
        private string $checksum,
    ) {
        $this->requests = $requests;
    }

    /**
     * Read the profile at its fixed package-relative location.
     *
     * @return  self  Validated profile referenced by the signed manifest asset inventory.
     *
     * @throws  InvalidArgumentException  When the packaged profile is unavailable or invalid.
     *
     * @since   2.0.0
     */
    public static function fromPackage(): self
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/policies/inspection-viewer.json');
        if (!is_string($contents)) {
            throw new InvalidArgumentException('The asset-inspection policy profile is unavailable.');
        }

        return self::fromJson($contents);
    }

    /**
     * Parse the closed signed profile into the platform's typed row and field policy models.
     *
     * @param   string  $json  Raw profile document from the package.
     *
     * @return  self  Validated profile safe for runtime evaluation and operator application.
     *
     * @throws  InvalidArgumentException  When the document has an unknown key, invalid type, or unsafe request.
     *
     * @since   2.0.0
     */
    public static function fromJson(string $json): self
    {
        if ($json === '' || strlen($json) > self::MAXIMUM_BYTES) {
            throw new InvalidArgumentException('The asset-inspection policy profile has an invalid size.');
        }
        try {
            $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The asset-inspection policy profile is invalid JSON.', 0, $exception);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InvalidArgumentException('The asset-inspection policy profile must be an object.');
        }
        self::keys($document, ['format', 'definition_id', 'row_policy', 'field_policy', 'policy_requests']);
        if (
            self::string($document, 'format') !== self::FORMAT
            || self::string($document, 'definition_id') !== Definitions::INSPECTION_DEFINITION_ID
        ) {
            throw new InvalidArgumentException('The asset-inspection policy profile identity is invalid.');
        }

        $row = self::object($document, 'row_policy');
        self::keys($row, ['schema', 'allows', 'denies']);
        $schemaDocument = self::object($row, 'schema');
        $schema = [];
        foreach ($schemaDocument as $handle => $type) {
            if (!is_string($handle) || !is_string($type)) {
                throw new InvalidArgumentException('The asset-inspection row-policy schema is invalid.');
            }
            $schema[$handle] = RecordPolicyValueType::tryFrom($type)
                ?? throw new InvalidArgumentException('The asset-inspection row-policy type is invalid.');
        }
        $recordSchema = new RecordPolicySchema($schema);
        $records = new RecordPolicySet(
            $recordSchema,
            self::predicates(self::list($row, 'allows')),
            self::predicates(self::list($row, 'denies')),
        );
        if ($records->toArray() !== [
            'schema' => ['risk_score' => 'integer'],
            'allows' => [[
                'type' => 'comparison',
                'field' => 'risk_score',
                'operator' => 'greater_than_or_equal',
                'value_type' => 'integer',
                'value' => 70,
            ]],
            'denies' => [],
        ]) {
            throw new InvalidArgumentException('The asset-inspection row policy contradicts its signed intent.');
        }

        $fieldDocument = self::object($document, 'field_policy');
        $fieldKeys = array_map(static fn (FieldAccessUsage $usage): string => $usage->value, FieldAccessUsage::cases());
        self::keys($fieldDocument, $fieldKeys);
        $fieldRules = [];
        foreach ($fieldKeys as $usage) {
            $values = self::list($fieldDocument, $usage);
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('An asset-inspection field-policy handle is invalid.');
                }
            }
            /** @var list<string> $values */
            $fieldRules[$usage] = $values;
        }
        $fields = new FieldDisclosurePlan($fieldRules);
        $safeFields = ['id', 'inspection_date', 'reference', 'risk_score'];
        foreach ($fields->toArray() as $allowed) {
            if (array_diff($allowed, $safeFields) !== []) {
                throw new InvalidArgumentException('The asset-inspection field policy discloses an unsafe field.');
            }
        }
        if (
            !$fields->allows(FieldAccessUsage::Detail, 'reference')
            || !$fields->allows(FieldAccessUsage::Detail, 'risk_score')
            || !$fields->allows(FieldAccessUsage::Report, 'risk_score')
            || !$fields->allows(FieldAccessUsage::Export, 'risk_score')
        ) {
            throw new InvalidArgumentException('The asset-inspection field policy omits its proof fields.');
        }

        $requests = self::requests(self::list($document, 'policy_requests'));
        if (array_column($requests, 'operation') !== [
            'business.record.browse',
            'business.record.export',
            'business.record.read',
            'business.record.report',
        ]) {
            throw new InvalidArgumentException('The asset-inspection policy operations are incomplete.');
        }

        return new self($records, $fields, $requests, CanonicalDefinitionJson::checksum($document));
    }

    /**
     * Return the typed row policy shared by deployment fixtures and contributed proof pages.
     *
     * @return  RecordPolicySet  Allow-only risk-score predicate with default-deny behavior.
     *
     * @since   2.0.0
     */
    public function records(): RecordPolicySet
    {
        return $this->records;
    }

    /**
     * Return the explicit field policy that excludes the restricted note from every usage.
     *
     * @return  FieldDisclosurePlan  Per-use safe field allowlists.
     *
     * @since   2.0.0
     */
    public function fields(): FieldDisclosurePlan
    {
        return $this->fields;
    }

    /**
     * Produce exact arguments for operator-owned core policy administration after activation.
     *
     * The `organizationId` remains null to make this proof profile site scoped, and the closed field rules are
     * copied onto every operation. The extension provider never calls this method to persist authority;
     * deployment acceptance or an operator does so under normal step-up controls.
     *
     * @return  list<array<string, mixed>>  Core `createResourcePolicy()` request documents.
     *
     * @since   2.0.0
     */
    public function administrationRequests(): array
    {
        $fieldRules = $this->fields->toArray() + ['actions' => []];

        return array_map(static fn (array $request): array => [
            'policyCode' => $request['policy_code'],
            'operation' => $request['operation'],
            'effect' => $request['effect'],
            'organizationId' => null,
            'definitionId' => Definitions::INSPECTION_DEFINITION_ID,
            'predicateType' => $request['predicate_type'],
            'field' => $request['field'],
            'operator' => $request['operator'],
            'valueType' => $request['value_type'],
            'value' => $request['value'],
            'fieldRules' => $fieldRules,
            'priority' => $request['priority'],
        ], $this->requests);
    }

    /**
     * Fingerprint the exact profile bytes after canonical decoding.
     *
     * @return  string  Lowercase SHA-256 suitable for acceptance evidence.
     *
     * @since   2.0.0
     */
    public function checksum(): string
    {
        return $this->checksum;
    }

    /**
     * Parse a list of closed comparison predicates.
     *
     * @param   list<mixed>  $documents  Predicate documents from one allow or deny set.
     *
     * @return  list<RecordPolicyPredicate>  Typed predicates validated again by their policy schema.
     *
     * @since   2.0.0
     */
    private static function predicates(array $documents): array
    {
        $predicates = [];
        foreach ($documents as $document) {
            if (!is_array($document) || array_is_list($document)) {
                throw new InvalidArgumentException('An asset-inspection row-policy predicate is invalid.');
            }
            self::keys($document, ['type', 'field', 'operator', 'value_type', 'value']);
            if (self::string($document, 'type') !== 'comparison') {
                throw new InvalidArgumentException('The asset-inspection row-policy predicate is unsupported.');
            }
            $operator = RecordPolicyComparisonOperator::tryFrom(self::string($document, 'operator'))
                ?? throw new InvalidArgumentException('The asset-inspection row-policy operator is invalid.');
            $valueType = RecordPolicyValueType::tryFrom(self::string($document, 'value_type'))
                ?? throw new InvalidArgumentException('The asset-inspection row-policy value type is invalid.');
            $value = $document['value'] ?? null;
            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                throw new InvalidArgumentException('The asset-inspection row-policy value is invalid.');
            }
            $predicates[] = new RecordPolicyComparison(
                self::string($document, 'field'),
                $operator,
                $valueType,
                $value,
            );
        }

        return $predicates;
    }

    /**
     * Validate policy administration request documents.
     *
     * @param   list<mixed>  $documents  Closed request objects in deterministic operation order.
     *
     * @return  list<array{
     *              policy_code: string,
     *              operation: string,
     *              effect: string,
     *              predicate_type: string,
     *              field: string,
     *              operator: string,
     *              value_type: string,
     *              value: string,
     *              priority: int
     *          }>  Validated operator requests.
     *
     * @since   2.0.0
     */
    private static function requests(array $documents): array
    {
        $requests = [];
        $codes = [];
        foreach ($documents as $document) {
            if (!is_array($document) || array_is_list($document)) {
                throw new InvalidArgumentException('An asset-inspection policy request is invalid.');
            }
            self::keys($document, [
                'policy_code', 'operation', 'effect', 'predicate_type', 'field', 'operator',
                'value_type', 'value', 'priority',
            ]);
            $request = [
                'policy_code' => self::string($document, 'policy_code'),
                'operation' => self::string($document, 'operation'),
                'effect' => self::string($document, 'effect'),
                'predicate_type' => self::string($document, 'predicate_type'),
                'field' => self::string($document, 'field'),
                'operator' => self::string($document, 'operator'),
                'value_type' => self::string($document, 'value_type'),
                'value' => self::string($document, 'value'),
                'priority' => self::integer($document, 'priority'),
            ];
            if (
                !str_starts_with($request['policy_code'], 'kumwe.asset-inspection-example.')
                || isset($codes[$request['policy_code']])
                || $request['effect'] !== 'allow'
                || $request['predicate_type'] !== 'comparison'
                || $request['field'] !== 'risk_score'
                || $request['operator'] !== RecordPolicyComparisonOperator::GreaterThanOrEqual->value
                || $request['value_type'] !== RecordPolicyValueType::Integer->value
                || $request['value'] !== '70'
                || $request['priority'] !== 100
            ) {
                throw new InvalidArgumentException('An asset-inspection policy request contradicts its profile.');
            }
            $codes[$request['policy_code']] = true;
            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * Require an exact object key set.
     *
     * @param   array<string, mixed>  $document  Object being checked.
     * @param   list<string>          $expected  Complete supported key list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function keys(array $document, array $expected): void
    {
        $actual = array_keys($document);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new InvalidArgumentException('The asset-inspection policy profile has unknown or missing keys.');
        }
    }

    /**
     * Read one non-empty string member.
     *
     * @param   array<string, mixed>  $document  Source object.
     * @param   string                $key       Member name.
     *
     * @return  string  Exact non-empty value.
     *
     * @since   2.0.0
     */
    private static function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('An asset-inspection policy profile string is invalid.');
        }

        return $value;
    }

    /**
     * Read one integer member.
     *
     * @param   array<string, mixed>  $document  Source object.
     * @param   string                $key       Member name.
     *
     * @return  int  Exact integer value.
     *
     * @since   2.0.0
     */
    private static function integer(array $document, string $key): int
    {
        $value = $document[$key] ?? null;
        if (!is_int($value)) {
            throw new InvalidArgumentException('An asset-inspection policy profile integer is invalid.');
        }

        return $value;
    }

    /**
     * Read one strict object member.
     *
     * @param   array<string, mixed>  $document  Source object.
     * @param   string                $key       Member name.
     *
     * @return  array<string, mixed>  String-keyed object.
     *
     * @since   2.0.0
     */
    private static function object(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('An asset-inspection policy profile object is invalid.');
        }

        return $value;
    }

    /**
     * Read one strict list member.
     *
     * @param   array<string, mixed>  $document  Source object.
     * @param   string                $key       Member name.
     *
     * @return  list<mixed>  Ordered list value.
     *
     * @since   2.0.0
     */
    private static function list(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('An asset-inspection policy profile list is invalid.');
        }

        return $value;
    }
}
