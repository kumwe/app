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
