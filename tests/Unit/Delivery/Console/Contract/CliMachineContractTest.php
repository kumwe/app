<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Contract;

use JsonException;
use Kumwe\App\Delivery\Console\Contract\CliMachineContract;
use Kumwe\App\Delivery\Console\Contract\CliV1MachineContract;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CliMachineContract::class)]
#[CoversClass(CliV1MachineContract::class)]
/**
 * Proves the retained CLI artifact closes its schema, invocation grammar, and compatibility identity.
 *
 * @since  2.0.0
 */
final class CliMachineContractTest extends TestCase
{
    /**
     * The retained artifact is complete, stable and executable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRetainedGenerationCoversAllLiveCommandNames(): void
    {
        $contract = CliV1MachineContract::contract();

        self::assertSame(1, $contract->generation());
        self::assertCount(44, $contract->commandNames());
        self::assertSame('access', $contract->commandNames()[0]);
        self::assertSame('user:recover-credentials', $contract->commandNames()[43]);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $contract->digest());
    }

    /**
     * Every action carries one closed effect class that consumers can inspect without widening authority.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testActionRisksAreClosedAndAvailableToConsumers(): void
    {
        $contract = CliV1MachineContract::contract();

        self::assertSame(['read', 'local-write', 'mutate', 'high-impact'], $contract->riskClasses());
        self::assertSame('read', $contract->actionRisk('app:health'));
        self::assertSame('local-write', $contract->actionRisk('extension:build'));
        self::assertSame('mutate', $contract->actionRisk('content', 'create'));
        self::assertSame('high-impact', $contract->actionRisk('access', 'reset-password'));
        self::assertSame('high-impact', $contract->actionRisk('extension:trust', 'emergency-revoke'));
    }

    /**
     * A recomputed surface digest cannot make an open-ended action classifier valid.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownActionRiskIsRejected(): void
    {
        $document = $this->document();
        self::assertIsArray($document['commands']);
        self::assertIsArray($document['commands'][0]);
        self::assertIsArray($document['commands'][0]['actions']);
        self::assertIsArray($document['commands'][0]['actions'][0]);
        $document['commands'][0]['actions'][0]['risk'] = 'unbounded';

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('unknown risk class "unbounded"');

        CliMachineContract::fromJson($this->encodeWithDigest($document));
    }

    /**
     * Two definitions cannot claim one dispatcher name, even with a recomputed digest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDuplicateCommandNamesAreRejected(): void
    {
        $document = $this->document();
        $commands = $document['commands'];
        self::assertIsArray($commands);
        $commands[] = $commands[0];
        $document['commands'] = $commands;

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('CLI command name "access" is duplicated');

        CliMachineContract::fromJson($this->encodeWithDigest($document));
    }

    /**
     * A raw secret-shaped option is forbidden even when actions and digest otherwise agree.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRawSecretOptionDefinitionIsRejected(): void
    {
        $document = $this->document();
        self::assertIsArray($document['commands']);
        self::assertIsArray($document['commands'][1]);
        self::assertIsArray($document['commands'][1]['options']);
        $document['commands'][1]['options'][] = [
            'name' => 'password',
            'type' => 'string',
            'enum' => [],
        ];

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains('Raw secret-shaped CLI input "password" is forbidden');

        CliMachineContract::fromJson($this->encodeWithDigest($document));
    }

    /**
     * Runtime validation closes unknown options and duplicate option occurrences.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvocationRejectsUnknownAndDuplicateOptions(): void
    {
        $contract = CliV1MachineContract::contract();

        try {
            $contract->validateInvocation('app:health', ['--unknown=value']);
            self::fail('An unknown option must not reach command code.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('--unknown option is unknown', $failure->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The --site option is duplicated');
        $contract->validateInvocation('mcp:serve', [
            '--site=main',
            '--site=other',
            '--token-file=/run/secrets/kumwe-token',
        ]);
    }

    /**
     * The first system administrator is global bootstrap state, not a tenant-scoped mutation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdministratorBootstrapRejectsTenantScope(): void
    {
        $contract = CliV1MachineContract::contract();
        $arguments = [
            '--email=administrator@example.test',
            '--name=Administrator',
            '--password-file=/run/secrets/kumwe-administrator-password',
        ];

        self::assertSame($arguments, $contract->validateInvocation('user:create-admin', $arguments));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The --site option is unknown');
        $contract->validateInvocation('user:create-admin', ['--site=default', ...$arguments]);
    }

    /**
     * Runtime validation enforces conditional and file-backed credential requirements.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConditionalRequirementsAreExecutable(): void
    {
        $contract = CliV1MachineContract::contract();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('The --site option is conditionally required');
        $contract->validateInvocation('token:create', [
            '--email=operator@example.test',
            '--name=automation',
            '--capabilities=content.read',
            '--token-file=/run/secrets/kumwe-token',
        ]);
    }

    /**
     * An option-first invocation reaches an action-based implementation with its declared default explicit.
     *
     * Action-based commands historically shifted the first implementation argument unconditionally, while
     * the retained grammar selected the default action without adding it to that vector. Normalization at the
     * contract boundary keeps validation and execution on the same interpretation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOptionFirstInvocationMaterializesTheDefaultActionForExecution(): void
    {
        $arguments = CliV1MachineContract::contract()->validateInvocation('extension:trust', [
            '--site=default',
            '--token-file=/run/secrets/kumwe-token',
        ]);

        self::assertSame([
            'list',
            '--site=default',
            '--token-file=/run/secrets/kumwe-token',
        ], $arguments);
    }

    /**
     * Exercise every retained scalar classifier with one accepted and one refused boundary value.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryInputClassifierEnforcesItsRuntimeBoundary(): void
    {
        $cases = [
            'absolute-path' => ['/tmp/input.json', 'relative/input.json'],
            'output-path' => ['C:\\exports\\report.csv', 'exports/report.csv'],
            'protected-json-file' => ['/run/input.json', 'input.json'],
            'protected-json-list-file' => ['/run/input-list.json', 'input-list.json'],
            'secret-file' => ['/run/secrets/token', 'token'],
            'base64' => ['YQ==', '***'],
            'boolean' => ['1', 'true'],
            'csv' => ['alpha,beta', ','],
            'enum' => ['alpha', 'beta'],
            'hex-digest' => [str_repeat('a', 64), 'abcd'],
            'identifier' => ['record-42', '   '],
            'string' => ['value', '   '],
            'json-object' => ['{"key":"value"}', '{invalid'],
            'json-object-list' => ['[{"key":"value"}]', '["value"]'],
            'non-negative-integer' => ['0', '-1'],
            'positive-integer' => ['1', '0'],
            'timestamp' => ['2026-08-22T12:00:00+00:00', 'not-a-timestamp'],
        ];

        foreach ($cases as $type => [$accepted, $refused]) {
            $enum = $type === 'enum' ? ['alpha'] : [];
            $name = $type === 'secret-file' ? 'value-file' : 'value';
            $contract = CliMachineContract::fromJson($this->encodeWithDigest(
                $this->minimalDocument($type, $enum, $name),
            ));
            self::assertSame(
                ['--' . $name . '=' . $accepted],
                $contract->validateInvocation('fixture', ['--' . $name . '=' . $accepted]),
                $type,
            );

            try {
                $contract->validateInvocation('fixture', ['--' . $name . '=' . $refused]);
                self::fail(sprintf('The invalid %s value was accepted.', $type));
            } catch (\InvalidArgumentException $failure) {
                self::assertStringContainsString('is not a valid', $failure->getMessage(), $type);
            }
        }

        $nullable = [
            'nullable-identifier' => '',
            'nullable-string' => '',
        ];
        foreach ($nullable as $type => $value) {
            $contract = CliMachineContract::fromJson($this->encodeWithDigest($this->minimalDocument($type)));
            self::assertSame(
                ['--value=' . $value],
                $contract->validateInvocation('fixture', ['--value=' . $value]),
            );
        }

        $flag = CliMachineContract::fromJson($this->encodeWithDigest($this->minimalDocument('flag')));
        self::assertSame(['--value'], $flag->validateInvocation('fixture', ['--value']));
        try {
            $flag->validateInvocation('fixture', ['--value=yes']);
            self::fail('A flag carrying a value was accepted.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('is a valueless flag', $failure->getMessage());
        }

        $valueRequired = CliMachineContract::fromJson($this->encodeWithDigest($this->minimalDocument('string')));
        try {
            $valueRequired->validateInvocation('fixture', ['--value']);
            self::fail('A scalar option without a value was accepted.');
        } catch (\InvalidArgumentException $failure) {
            self::assertStringContainsString('requires a value', $failure->getMessage());
        }

        $objectList = CliMachineContract::fromJson($this->encodeWithDigest(
            $this->minimalDocument('json-object-list'),
        ));
        foreach (['{invalid', '{"key":"value"}'] as $refused) {
            try {
                $objectList->validateInvocation('fixture', ['--value=' . $refused]);
                self::fail('A non-object-list JSON value was accepted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringContainsString('is not a valid json-object-list', $failure->getMessage());
            }
        }
    }

    /**
     * Refuse malformed document shapes at every layer of the closed CLI schema.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentSchemaRefusesMalformedNestedBoundaries(): void
    {
        /** @var array<string, array{callable, string}> $cases */
        $cases = [
            'open top-level keys' => [
                static fn (array &$document): mixed => $document['extra'] = true,
                'keys are not closed',
            ],
            'empty format' => [
                static fn (array &$document): mixed => $document['format'] = '',
                'must be a non-empty string',
            ],
            'unsupported format' => [
                static fn (array &$document): mixed => $document['format'] = 'future',
                'format is unsupported',
            ],
            'non-integer generation' => [
                static fn (array &$document): mixed => $document['generation'] = '1',
                'must be an integer',
            ],
            'non-positive generation' => [
                static fn (array &$document): mixed => $document['generation'] = 0,
                'generation must be positive',
            ],
            'non-retained status' => [
                static fn (array &$document): mixed => $document['status'] = 'draft',
                'generation must be retained',
            ],
            'input vocabulary drift' => [
                static function (array &$document): void {
                    array_pop($document['input_types']);
                },
                'input classifier set drifted',
            ],
            'non-string input vocabulary' => [
                static fn (array &$document): mixed => $document['input_types'][0] = 42,
                'must contain strings',
            ],
            'output vocabulary drift' => [
                static function (array &$document): void {
                    array_pop($document['output_modes']);
                },
                'output mode set drifted',
            ],
            'risk vocabulary drift' => [
                static function (array &$document): void {
                    array_pop($document['risk_classes']);
                },
                'risk classifier set drifted',
            ],
            'dispatcher is a list' => [
                static fn (array &$document): mixed => $document['dispatcher'] = [],
                'must be an object',
            ],
            'dispatcher exit out of range' => [
                static fn (array &$document): mixed => $document['dispatcher']['list_exit'] = 256,
                'outside the portable range',
            ],
            'no commands' => [
                static fn (array &$document): mixed => $document['commands'] = [],
                'declares no commands',
            ],
            'non-object command' => [
                static fn (array &$document): mixed => $document['commands'] = ['fixture'],
                'command definition must be an object',
            ],
            'invalid command name' => [
                static fn (array &$document): mixed => $document['commands'][0]['name'] = 'Fixture',
                'command name "Fixture" is invalid',
            ],
            'non-boolean action selector' => [
                static fn (array &$document): mixed => $document['commands'][0]['action_argument'] = 1,
                'must be a boolean',
            ],
            'non-object option' => [
                static fn (array &$document): mixed => $document['commands'][0]['options'] = ['value'],
                'invalid option definition',
            ],
            'duplicate option' => [
                static function (array &$document): void {
                    $document['commands'][0]['options'][] = $document['commands'][0]['options'][0];
                },
                'duplicates option "value"',
            ],
            'invalid output mode' => [
                static fn (array &$document): mixed => $document['commands'][0]['output']['stdout'] = 'binary',
                'invalid stdout mode',
            ],
            'non-object exit map' => [
                static fn (array &$document): mixed => $document['commands'][0]['exit_codes'] = 'success',
                'exit_codes" must be an object',
            ],
            'empty exit map' => [
                static fn (array &$document): mixed => $document['commands'][0]['exit_codes'] = [],
                'declares no exit codes',
            ],
            'invalid exit declaration' => [
                static fn (array &$document): mixed => $document['commands'][0]['exit_codes'] = ['999' => 'failure'],
                'invalid exit declaration',
            ],
            'non-object action' => [
                static fn (array &$document): mixed => $document['commands'][0]['actions'] = ['run'],
                'invalid action definition',
            ],
            'duplicate action' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][] = $document['commands'][0]['actions'][0];
                },
                'duplicates action "run"',
            ],
            'no actions' => [
                static fn (array &$document): mixed => $document['commands'][0]['actions'] = [],
                'declares no actions',
            ],
            'undeclared default action' => [
                static fn (array &$document): mixed => $document['commands'][0]['default_action'] = 'other',
                'undeclared default action',
            ],
            'missing default without selector' => [
                static fn (array &$document): mixed => $document['commands'][0]['default_action'] = null,
                'requires a default action',
            ],
            'invalid action name' => [
                static fn (array &$document): mixed => $document['commands'][0]['actions'][0]['name'] = 'Run',
                'invalid action "Run"',
            ],
            'invalid action risk' => [
                static fn (array &$document): mixed => $document['commands'][0]['actions'][0]['risk'] = 'unbounded',
                'unknown risk class "unbounded"',
            ],
            'non-object positional' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['positionals'] = ['value'];
                },
                'invalid positional definition',
            ],
            'duplicate positional' => [
                static function (array &$document): void {
                    $positional = ['name' => 'record', 'type' => 'identifier', 'required' => true, 'enum' => []];
                    $document['commands'][0]['actions'][0]['positionals'] = [$positional, $positional];
                },
                'duplicates positional "record"',
            ],
            'required positional after optional' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['positionals'] = [
                        ['name' => 'first', 'type' => 'identifier', 'required' => false, 'enum' => []],
                        ['name' => 'second', 'type' => 'identifier', 'required' => true, 'enum' => []],
                    ];
                },
                'required positional after an optional one',
            ],
            'undeclared option reference' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['allowed_options'][] = 'other';
                },
                'references undeclared option "other"',
            ],
            'duplicate option reference' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['allowed_options'][] = 'value';
                },
                'duplicates option reference "value"',
            ],
            'non-list option references' => [
                static fn (array &$document): mixed => $document['commands'][0]['actions'][0]['allowed_options'] = [
                    'value' => true,
                ],
                'must be a list',
            ],
            'invalid option group' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['one_of_options'] = [['value']];
                },
                'invalid one_of_options group',
            ],
            'non-string option group reference' => [
                static function (array &$document): void {
                    $document['commands'][0]['options'][] = ['name' => 'other', 'type' => 'string', 'enum' => []];
                    $document['commands'][0]['actions'][0]['allowed_options'][] = 'other';
                    $document['commands'][0]['actions'][0]['one_of_options'] = [['value', 42]];
                },
                'non-string option reference',
            ],
            'non-object condition' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['conditional_requirements'] = ['value'];
                },
                'invalid conditional requirement',
            ],
            'unsupported condition operator' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['conditional_requirements'] = [[
                        'option' => 'value', 'operator' => 'contains', 'value' => 'x', 'require' => ['value'],
                    ]];
                },
                'unsupported condition operator',
            ],
            'equals condition without value' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['conditional_requirements'] = [[
                        'option' => 'value', 'operator' => 'equals', 'value' => null, 'require' => ['value'],
                    ]];
                },
                'condition without a string value',
            ],
            'present condition with value' => [
                static function (array &$document): void {
                    $document['commands'][0]['actions'][0]['conditional_requirements'] = [[
                        'option' => 'value', 'operator' => 'present', 'value' => 'x', 'require' => ['value'],
                    ]];
                },
                'present condition with a value',
            ],
            'invalid input name' => [
                static fn (array &$document): mixed => $document['commands'][0]['options'][0]['name'] = 'Value',
                'input name "Value" is invalid',
            ],
            'unknown input type' => [
                static fn (array &$document): mixed => $document['commands'][0]['options'][0]['type'] = 'uuid',
                'unknown type "uuid"',
            ],
            'invalid enum declaration' => [
                static fn (array &$document): mixed => $document['commands'][0]['options'][0]['enum'] = ['alpha'],
                'invalid enum declaration',
            ],
            'non-file-backed secret' => [
                static function (array &$document): void {
                    $document['commands'][0]['options'][0]['name'] = 'credential-file';
                    $document['commands'][0]['options'][0]['type'] = 'string';
                },
                'Raw secret-shaped CLI input',
            ],
            'secret type without file name' => [
                static function (array &$document): void {
                    $document['commands'][0]['options'][0]['name'] = 'token';
                    $document['commands'][0]['options'][0]['type'] = 'secret-file';
                },
                'must be file-backed',
            ],
        ];

        foreach ($cases as $label => [$corrupt, $message]) {
            $document = $this->minimalDocument('string');
            $corrupt($document);

            try {
                CliMachineContract::fromJson($this->encodeWithDigest($document));
                self::fail(sprintf('The %s boundary was accepted.', $label));
            } catch (LogicException $failure) {
                self::assertStringContainsString($message, $failure->getMessage(), $label);
            }
        }

        $document = $this->minimalDocument('string');
        $document['surface_digest'] = 'sha256:' . str_repeat('0', 64);
        try {
            CliMachineContract::fromJson(json_encode($document, JSON_THROW_ON_ERROR));
            self::fail('A stale surface digest was accepted.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('surface digest does not match', $failure->getMessage());
        }
    }

    /**
     * Exercise action selection, positional ordering and defensive runtime drift checks.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testInvocationAndRuntimeDriftRefusalsAreExecutable(): void
    {
        $document = $this->minimalDocument('string');
        $document['commands'][0]['action_argument'] = true;
        $document['commands'][0]['default_action'] = null;
        $document['commands'][0]['actions'][0]['positionals'] = [[
            'name' => 'record',
            'type' => 'identifier',
            'required' => true,
            'enum' => [],
        ]];
        $contract = CliMachineContract::fromJson($this->encodeWithDigest($document));
        self::assertSame(
            ['run', 'record-42', '--value=alpha'],
            $contract->validateInvocation('fixture', ['run', 'record-42', '--value=alpha']),
        );

        foreach (
            [
                ['fixture', [], 'requires an action'],
                ['fixture', ['run', '--value=alpha', 'record-42'], 'Positional arguments must precede'],
            ] as [$command, $arguments, $message]
        ) {
            try {
                $contract->validateInvocation($command, $arguments);
                self::fail('An invalid action vector was accepted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringContainsString($message, $failure->getMessage());
            }
        }

        foreach (['actionRisk', 'validateInvocation', 'assertExitCode'] as $operation) {
            try {
                if ($operation === 'actionRisk') {
                    $contract->actionRisk('missing');
                } elseif ($operation === 'validateInvocation') {
                    $contract->validateInvocation('missing', []);
                } else {
                    $contract->assertExitCode('missing', 0);
                }
                self::fail(sprintf('%s accepted an undeclared command.', $operation));
            } catch (LogicException $failure) {
                self::assertStringContainsString('has no CLI contract', $failure->getMessage());
            }
        }

        try {
            $contract->actionRisk('fixture');
            self::fail('Risk lookup guessed an absent default action.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('has no default action', $failure->getMessage());
        }

        $valid = CliMachineContract::fromJson($this->encodeWithDigest($this->minimalDocument('string')));
        $property = new \ReflectionProperty($valid, 'commands');
        $commands = $property->getValue($valid);
        self::assertIsArray($commands);
        $commands['fixture']['default_action'] = 42;
        $property->setValue($valid, $commands);
        try {
            $valid->validateInvocation('fixture', []);
            self::fail('A drifted default action reached command code.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('invalid default action', $failure->getMessage());
        }

        $riskContract = CliMachineContract::fromJson($this->encodeWithDigest($this->minimalDocument('string')));
        $riskCommands = $property->getValue($riskContract);
        self::assertIsArray($riskCommands);
        $riskCommands['fixture']['actions'][0]['risk'] = 'unbounded';
        $property->setValue($riskContract, $riskCommands);
        try {
            $riskContract->actionRisk('fixture');
            self::fail('A drifted risk class was returned to a consumer.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('unknown risk class', $failure->getMessage());
        }

        $validJson = $this->encodeWithDigest($this->minimalDocument('string'));
        self::assertSame(['fixture'], CliMachineContract::fromJson($validJson)->commandNames());

        try {
            CliMachineContract::fromJson('[]');
            self::fail('A list-shaped contract was accepted.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('must be a JSON object', $failure->getMessage());
        }

        $reflection = new \ReflectionClass(CliMachineContract::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        try {
            $constructor->invoke($reflection->newInstanceWithoutConstructor(), [
                'commands' => ['not-an-object'],
            ]);
            self::fail('The constructor accepted a non-object command after validation.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('command definition must be an object', $failure->getMessage());
        }
    }

    /**
     * Build the smallest valid document needed to exercise an input classifier.
     *
     * @param   string        $type  Retained input classifier.
     * @param   list<string>  $enum  Closed enum vocabulary when the classifier is `enum`.
     * @param   string        $name  Option name satisfying any classifier-specific naming invariant.
     *
     * @return  array<string, mixed>  Valid one-command contract document.
     *
     * @since   2.0.0
     */
    private function minimalDocument(string $type, array $enum = [], string $name = 'value'): array
    {
        $document = $this->document();
        $document['commands'] = [[
            'name' => 'fixture',
            'action_argument' => false,
            'default_action' => 'run',
            'options' => [[
                'name' => $name,
                'type' => $type,
                'enum' => $enum,
            ]],
            'output' => [
                'stdout' => 'none',
                'stderr' => 'text-lines',
            ],
            'exit_codes' => [
                '0' => 'success',
                '1' => 'failure',
            ],
            'actions' => [[
                'name' => 'run',
                'risk' => 'read',
                'positionals' => [],
                'allowed_options' => [$name],
                'required_options' => [],
                'one_of_options' => [],
                'mutually_exclusive_options' => [],
                'conditional_requirements' => [],
            ]],
        ]];

        return $document;
    }

    /**
     * Read a decoded copy of the authoritative artifact for mutation tests.
     *
     * @return  array<string, mixed>  Decoded contract document.
     *
     * @throws  JsonException  When the committed fixture is malformed.
     *
     * @since   2.0.0
     */
    private function document(): array
    {
        $document = json_decode(CliV1MachineContract::json(), true, 128, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertFalse(array_is_list($document));

        /** @var array<string, mixed> $document */
        return $document;
    }

    /**
     * Recompute the commands digest and encode a mutated document.
     *
     * @param   array<string, mixed>  $document  Mutated decoded document.
     *
     * @return  string  Compact JSON with a matching surface digest.
     *
     * @throws  JsonException  When the mutation cannot be encoded.
     *
     * @since   2.0.0
     */
    private function encodeWithDigest(array $document): string
    {
        $commands = $document['commands'] ?? null;
        self::assertIsArray($commands);
        self::assertTrue(array_is_list($commands));
        $document['surface_digest'] = CliMachineContract::surfaceDigest($commands);

        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
