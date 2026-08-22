<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use JsonException;
use LogicException;
use Mcp\Capability\Discovery\DocBlockParser;
use ReflectionMethod;
use ReflectionNamedType;
use stdClass;

/**
 * Generates the retained MCP v1 compatibility contract from the live capability catalogue.
 *
 * The document is deliberately generated from `McpCapabilityCatalog`, which is also the only input used
 * by `KumweMcpServerFactory`. That makes the fixture a freeze of the surface the server actually registers,
 * not a second hand-maintained inventory. Lists retain registration order while object keys are sorted for
 * the digest, so reordering JSON members is harmless but changing a name, schema, annotation, policy hint or
 * handler binding is an explicit compatibility event.
 *
 * @since  2.0.0
 */
final readonly class McpMachineContract
{
    /**
     * Retained contract generation identifier.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string GENERATION = 'mcp-v1';

    /**
     * Canonical tool-error envelope identifier.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string ERROR_SCHEMA = 'kumwe.mcp.tool-error.v1';

    /**
     * Number of tools retained by this generation.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int TOOL_COUNT = 75;

    /**
     * Number of resources retained by this generation.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int RESOURCE_COUNT = 1;

    /**
     * Number of prompts retained by this generation.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int PROMPT_COUNT = 1;

    /**
     * Bind generation to the same catalogue the live server registers.
     *
     * @param  McpCapabilityCatalog  $catalog  Sole declaration of tools, resources and prompts.
     *
     * @since  2.0.0
     */
    public function __construct(private McpCapabilityCatalog $catalog)
    {
    }

    /**
     * Build the complete retained compatibility fixture.
     *
     * @return  array<string, mixed>  Machine-readable generation, exclusions and canonical surface.
     *
     * @throws  LogicException  When the live inventory no longer belongs to this retained generation.
     * @throws  JsonException  When the canonical surface cannot be encoded.
     *
     * @since   2.0.0
     */
    public function document(): array
    {
        $surface = $this->surface();
        $errorRegistry = McpToolErrorVocabulary::registry();
        $toolError = [
            'schema' => self::ERROR_SCHEMA,
            'transport' => 'CallToolResult',
            'isError' => true,
            'content_type' => 'text',
            'members' => ['schema', 'code', 'message', 'retryable'],
            'unexpected_failure' => 'generic_protocol_error_and_server_log',
            'registry_sha256' => hash('sha256', self::canonicalJson($errorRegistry)),
            'registry' => $errorRegistry,
        ];
        $counts = [
            'tools' => count($surface['tools']),
            'resources' => count($surface['resources']),
            'prompts' => count($surface['prompts']),
        ];
        if (
            $counts !== [
                'tools' => self::TOOL_COUNT,
                'resources' => self::RESOURCE_COUNT,
                'prompts' => self::PROMPT_COUNT,
            ]
        ) {
            throw new LogicException(sprintf(
                'MCP %s retains %d tools, %d resources and %d prompts; the live catalogue has %d, %d and %d.',
                self::GENERATION,
                self::TOOL_COUNT,
                self::RESOURCE_COUNT,
                self::PROMPT_COUNT,
                $counts['tools'],
                $counts['resources'],
                $counts['prompts'],
            ));
        }

        return [
            'format' => 'kumwe-mcp-machine-contract-v1',
            'generation' => [
                'id' => self::GENERATION,
                'status' => 'retained',
                'introduced_in' => '2.0.0',
                'protocol_version' => '2025-11-25',
                'sdk' => [
                    'package' => 'mcp/sdk',
                    'version' => 'v0.7.1',
                    'source_reference' => '785fc3b9b7006ecc8a73322c939d96a4a7154345',
                ],
            ],
            'server' => [
                'name' => 'Kumwe App',
                'capabilities' => [
                    'prompts' => new stdClass(),
                    'resources' => new stdClass(),
                    'tools' => new stdClass(),
                ],
                'unsupported_capabilities' => ['logging', 'completions', 'resource_templates'],
            ],
            'inventory' => $counts,
            'tool_error' => $toolError,
            'intentional_exclusions' => $this->intentionalExclusions(),
            'contract_sha256' => hash('sha256', self::canonicalJson([
                'surface' => $surface,
                'tool_error' => $toolError,
            ])),
            'surface' => $surface,
        ];
    }

    /**
     * Encode a document as stable human-reviewable JSON.
     *
     * @param   array<string, mixed>  $document  Contract document to encode.
     *
     * @return  string  Pretty JSON ending in exactly one newline.
     *
     * @throws  JsonException  When the document cannot be encoded.
     *
     * @since   2.0.0
     */
    public static function prettyJson(array $document): string
    {
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /**
     * Encode any contract value into digest-stable canonical JSON.
     *
     * List order is semantic because it is registration and pagination order. Associative object keys are
     * sorted recursively so PHP construction order never becomes compatibility by accident.
     *
     * @param   mixed  $value  JSON-compatible value.
     *
     * @return  string  Compact recursively canonical JSON.
     *
     * @throws  JsonException  When the value cannot be encoded.
     *
     * @since   2.0.0
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * Project the complete registration input into JSON-compatible values.
     *
     * @return  array{tools: list<array<string, mixed>>, resources: list<array<string, mixed>>,
     *          prompts: list<array<string, mixed>>}  Live registration surface in catalogue order.
     *
     * @since   2.0.0
     */
    private function surface(): array
    {
        $tools = [];
        foreach ($this->catalog->tools() as $tool) {
            $tools[] = [
                'name' => $tool['name'],
                'title' => $tool['title'],
                'description' => $tool['description'],
                'handler' => $tool['handler'],
                'capability' => $tool['capability'],
                'risk' => $tool['risk']->value,
                'alternative' => $tool['alternative'],
                'annotations' => [
                    'title' => $tool['title'],
                    'readOnlyHint' => $tool['readOnly'],
                    'destructiveHint' => $tool['destructive'],
                    'idempotentHint' => $tool['idempotent'],
                    'openWorldHint' => false,
                ],
                'inputSchema' => McpJsonSchemaNormalizer::normalize($tool['inputSchema']),
                'outputSchema' => McpJsonSchemaNormalizer::normalize($tool['outputSchema']),
            ];
        }

        $prompts = [];
        $docBlocks = new DocBlockParser();
        foreach ($this->catalog->prompts() as $prompt) {
            $reflection = new ReflectionMethod(KumweMcpHandlers::class, $prompt['handler']);
            $documentation = $reflection->getDocComment();
            $tags = $docBlocks->getParamTags($docBlocks->parseDocBlock(
                $documentation === false ? null : $documentation,
            ));
            $arguments = [];
            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    continue;
                }
                $tag = $tags['$' . $parameter->getName()] ?? null;
                $arguments[] = [
                    'name' => $parameter->getName(),
                    'description' => $tag === null ? null : trim((string) $tag->getDescription()),
                    'required' => !$parameter->isOptional() && !$parameter->isDefaultValueAvailable(),
                ];
            }
            $prompts[] = [...$prompt, 'arguments' => $arguments];
        }

        return [
            'tools' => $tools,
            'resources' => $this->catalog->resources(),
            'prompts' => $prompts,
        ];
    }

    /**
     * State every deliberately absent high-risk operation as data rather than an undocumented omission.
     *
     * @return  list<array{id: string, operations: list<string>, control: string, alternatives: list<string>}>
     *          Closed inventory of deliberate MCP exclusions.
     *
     * @since   2.0.0
     */
    private function intentionalExclusions(): array
    {
        return [
            [
                'id' => 'credential_issuance',
                'operations' => ['kumwe_token_create', 'kumwe_token_rotate'],
                'control' => 'A model tool never accepts an authentication secret or returns a newly issued one.',
                'alternatives' => ['administrator', 'protected_rest', 'protected_cli'],
            ],
            [
                'id' => 'destructive_schema_planning',
                'operations' => ['kumwe_business_schema_purge_plan_create'],
                'control' => 'Purge planning requires current-password step-up, which bearer MCP cannot prove.',
                'alternatives' => ['administrator', 'protected_cli'],
            ],
            [
                'id' => 'high_impact_schema_approval',
                'operations' => ['kumwe_business_schema_plan_approve_high_impact'],
                'control' => 'High-impact approval requires a fresh human proof bound to the inspected checksum.',
                'alternatives' => ['administrator', 'protected_cli'],
            ],
            [
                'id' => 'approval_votes',
                'operations' => ['kumwe_business_approval_approve', 'kumwe_business_approval_reject'],
                'control' => 'Maker-checker votes require an independent stepped-up human session.',
                'alternatives' => ['administrator', 'portal'],
            ],
            [
                'id' => 'arbitrary_extension_installation',
                'operations' => ['kumwe_extension_install', 'kumwe_extension_grant_permissions'],
                'control' => 'Package admission and permission grants require supply-chain and operator controls.',
                'alternatives' => ['administrator', 'protected_cli'],
            ],
        ];
    }

    /**
     * Sort JSON-object keys recursively while preserving list order.
     *
     * @param   mixed  $value  Candidate JSON value.
     *
     * @return  mixed  Canonicalized value.
     *
     * @since   2.0.0
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $member) {
            $value[$key] = self::canonicalize($member);
        }

        return $value;
    }
}
