<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Content\Application\ContentModelService;
use Kumwe\App\Content\Application\ContentService;
use Kumwe\App\Delivery\Console\Command\CommandInput;
use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Delivery\Console\Command\StudioAuthoringCommand;
use Kumwe\App\Delivery\Console\Contract\CliV1MachineContract;
use Kumwe\App\Delivery\Console\Contract\CliV2MachineContract;
use Kumwe\App\Delivery\Console\ConsoleApplication;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringContextAuthority;
use Kumwe\App\Studio\Application\Authoring\ContentStudioAuthoringTargetResolver;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Host\StudioProducerHostFactory;
use Kumwe\App\Tests\Support\AuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

/**
 * Pins the console face of Studio authoring: the generation-two contract, the JSON failure envelope and exits.
 *
 * Generation two must be generation one plus exactly the `studio-authoring` command. Its failures print the
 * Studio category, diagnostics and closed stable code and exit with the portable status for that category;
 * operation arguments come only from protected files decoded with their empty objects intact.
 *
 * @since  2.0.0
 */
#[CoversClass(StudioAuthoringCommand::class)]
#[CoversClass(CliV2MachineContract::class)]
#[CoversClass(CommandInput::class)]
#[CoversClass(ConsoleApplication::class)]
final class StudioAuthoringCommandTest extends TestCase
{
    /**
     * Commands generation two adds to generation one, in no particular order.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array SUCCESSOR_COMMANDS = [
        'business-approval',
        'business-bulk',
        'business-security',
        'media',
        'security-events',
        'studio-authoring',
        'wording',
    ];

    /**
     * Protected files written by a test.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private array $files = [];

    /**
     * Remove every protected file a test wrote.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->files = [];
    }

    /**
     * Generation two only adds commands — studio-authoring and the browser-parity commands — to generation one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGenerationTwoIsGenerationOnePlusStudioAuthoring(): void
    {
        $v1 = json_decode(CliV1MachineContract::json(), true, 128, JSON_THROW_ON_ERROR);
        $v2 = json_decode(CliV2MachineContract::json(), true, 128, JSON_THROW_ON_ERROR);
        self::assertIsArray($v1);
        self::assertIsArray($v2);
        self::assertSame(2, CliV2MachineContract::contract()->generation());
        self::assertSame(
            self::sortedWith(CliV1MachineContract::contract()->commandNames(), self::SUCCESSOR_COMMANDS),
            CliV2MachineContract::contract()->commandNames(),
        );
        $retained = array_values(array_filter(
            $v2['commands'],
            static fn (array $command): bool => !in_array($command['name'], self::SUCCESSOR_COMMANDS, true),
        ));
        self::assertSame($v1['commands'], $retained);
        self::assertSame(
            ['read', 'read', 'read', 'mutate', 'read', 'mutate', 'mutate', 'mutate'],
            array_map(
                static fn (string $action): string =>
                    CliV2MachineContract::contract()->actionRisk('studio-authoring', $action),
                ['open', 'resolve-target', 'list-types', 'start', 'plan-save', 'save-item', 'save-as-new-type',
                    'save-new-type-version'],
            ),
        );
    }

    /**
     * The command names itself and a catalogue summary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNameAndDescription(): void
    {
        $command = self::command(null);
        self::assertSame('studio-authoring', $command->name());
        self::assertSame('core.console.studio_authoring.description', $command->description());
    }

    /**
     * Refusals print the closed envelope and exit with the portable status.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRefusalsPrintTheStudioEnvelopeAndExitPortably(): void
    {
        $unauthenticated = self::command(null);
        [$status, $error] = self::dispatch($unauthenticated, ['open', '--intent=create'], $this->file('token'));
        self::assertSame(77, $status);
        self::assertSame('studio_authoring.forbidden', $error->error->code);
        self::assertSame(['studio.machine/credential-refused'], $error->error->details->diagnostics);
        self::assertSame('core.console.studio_authoring.refused', $error->error->message);

        $authenticated = self::command(AuthorizationContext::principal(['content.read']));
        self::assertSame(
            65,
            $authenticated->execute(
                ['open', '--site=default', '--token-file=' . $this->file('token')],
                new NullOutput(),
            ),
            'An open without an intent is refused as invalid data.',
        );

        [$status, $error] = self::dispatch($authenticated, ['open', '--intent=create'], '/not/protected');
        self::assertSame(65, $status);
        self::assertSame(['studio.machine/request-invalid'], $error->error->details->diagnostics);

        [$status, $error] = self::dispatch(
            $authenticated,
            ['plan-save', '--session=contexts/k', '--session-generation=g', '--argument-file=' . $this->file('[]')],
            $this->file('token'),
        );
        self::assertSame(65, $status);
        self::assertSame(['studio.machine/request-invalid'], $error->error->details->diagnostics);

        self::assertSame(
            64,
            (new ConsoleApplication([$authenticated], new NullOutput()))->run([
                'bin/kumwe',
                'studio-authoring',
                'save-item',
                '--site=default',
                '--token-file=' . $this->file('token'),
                '--session=contexts/k',
                '--session-generation=g',
                '--argument-file=' . $this->file('{}'),
            ]),
            'The frozen grammar refuses a mutation without --operation-id before the command runs.',
        );
    }

    /**
     * The command re-reads a reusable-type version strictly even when it is invoked beneath the grammar.
     *
     * The frozen console grammar already refuses a version that is not a positive integer, or one given
     * without a type. The command does not rely on that: invoked directly, a malformed version is refused as
     * malformed input and a well-formed version without a type is refused by the gateway as a target it will
     * not guess, both with the invalid-data status and before any Content is read.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommandReReadsATypeVersionStrictlyBeneathTheGrammar(): void
    {
        $authenticated = self::command(AuthorizationContext::principal(['content.read']));
        $token = '--token-file=' . $this->file('token');

        foreach (
            [
                'malformed version' => ['--content-type=type-1', '--content-type-version=zero'],
                'version without a type' => ['--content-type-version=2'],
            ] as $case => $options
        ) {
            $arguments = ['open', '--site=default', $token, '--intent=create', ...$options];
            self::assertSame(65, $authenticated->execute($arguments, new NullOutput()), $case);
        }
    }

    /**
     * Protected JSON documents keep empty objects as objects.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProtectedJsonDocumentsPreserveEmptyObjects(): void
    {
        $document = CommandInput::protectedJsonDocument($this->file('{"values":{},"list":[]}'));
        self::assertInstanceOf(stdClass::class, $document->values);
        self::assertSame([], $document->list);
        $this->expectException(\InvalidArgumentException::class);
        CommandInput::protectedJsonDocument($this->file('[]'));
    }

    /**
     * Sort one generation-one name list with the names generation two adds.
     *
     * @param   list<string>  $names  Generation-one names.
     * @param   list<string>  $added  Added names.
     *
     * @return  list<string>  Sorted list.
     *
     * @since   2.0.0
     */
    private static function sortedWith(array $names, array $added): array
    {
        array_push($names, ...$added);
        sort($names);

        return $names;
    }

    /**
     * Build the command over a stubbed token verifier and an unreachable gateway.
     *
     * @param   ?AuthenticatedPrincipal  $principal  Principal every token verifies to, or null for none.
     *
     * @return  StudioAuthoringCommand  Command under test.
     *
     * @since   2.0.0
     */
    private static function command(?AuthenticatedPrincipal $principal): StudioAuthoringCommand
    {
        $tokens = new class ($principal) implements AccessTokenVerifier {
            /**
             * Bind the principal every token verifies to.
             *
             * @param  ?AuthenticatedPrincipal  $principal  Principal, or null for none.
             *
             * @since  2.0.0
             */
            public function __construct(private ?AuthenticatedPrincipal $principal)
            {
            }

            /**
             * Verify any token to the bound principal.
             *
             * @param   string  $token           Ignored token.
             * @param   string  $audience        Ignored audience.
             * @param   string  $purpose         Ignored purpose.
             * @param   string  $siteIdentifier  Ignored site.
             *
             * @return  ?AuthenticatedPrincipal  The bound principal.
             *
             * @since   2.0.0
             */
            public function verify(
                string $token,
                string $audience = 'kumwe-http',
                string $purpose = 'api',
                string $siteIdentifier = 'default',
            ): ?AuthenticatedPrincipal {
                return $this->principal;
            }
        };
        $bare = static fn (string $class): object => (new ReflectionClass($class))->newInstanceWithoutConstructor();

        return new StudioAuthoringCommand(
            new StudioMachineAuthoringGateway(
                $bare(ContentStudioAuthoringContextAuthority::class),
                $bare(StudioHostSessionAuthority::class),
                $bare(ContentStudioAuthoringTargetResolver::class),
                $bare(ContentService::class),
                $bare(ContentModelService::class),
                $bare(StudioProducerHostFactory::class),
            ),
            new ConsoleAuthorizer($tokens),
        );
    }

    /**
     * Dispatch one invocation through the generation-two console and decode its failure envelope.
     *
     * @param   StudioAuthoringCommand  $command    Command under test.
     * @param   list<string>            $arguments  Action and action options.
     * @param   string                  $tokenFile  Token file path.
     *
     * @return  array{int, stdClass}  Exit status and decoded failure envelope.
     *
     * @since   2.0.0
     */
    private static function dispatch(StudioAuthoringCommand $command, array $arguments, string $tokenFile): array
    {
        $output = new class implements Output {
            /**
             * Failure lines.
             *
             * @var    list<string>
             * @since  2.0.0
             */
            public array $errors = [];

            /**
             * Ignore one catalogue message.
             *
             * @param   string                                                   $identifier  Identifier.
             * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Placeholders.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function message(string $identifier, array $parameters = []): void
            {
            }

            /**
             * Record one catalogue failure identifier.
             *
             * @param   string                                                   $identifier  Identifier.
             * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Placeholders.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function failure(string $identifier, array $parameters = []): void
            {
                $this->errors[] = $identifier;
            }

            /**
             * Return the identifier as its own text.
             *
             * @param   string                                                   $identifier  Identifier.
             * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Placeholders.
             *
             * @return  string  The identifier.
             *
             * @since   2.0.0
             */
            public function text(string $identifier, array $parameters = []): string
            {
                return $identifier;
            }

            /**
             * Ignore one ordinary line.
             *
             * @param   string  $message  Line.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function line(string $message): void
            {
            }

            /**
             * Record one failure line.
             *
             * @param   string  $message  Line.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function error(string $message): void
            {
                $this->errors[] = $message;
            }
        };
        $status = (new ConsoleApplication([$command], $output))->run([
            'bin/kumwe',
            'studio-authoring',
            ...$arguments,
            '--site=default',
            '--token-file=' . $tokenFile,
        ]);
        $decoded = json_decode(implode("\n", $output->errors), false, 32, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $decoded);

        return [$status, $decoded];
    }

    /**
     * Write one owner-only protected file.
     *
     * @param   string  $contents  File contents.
     *
     * @return  string  Absolute path.
     *
     * @since   2.0.0
     */
    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kumwe-studio-cli-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        chmod($path, 0o600);
        $this->files[] = $path;

        return $path;
    }
}

/**
 * Output sink that discards everything, for asserting on exit status alone.
 *
 * @since  2.0.0
 */
final class NullOutput implements Output
{
    /**
     * Discard one catalogue message.
     *
     * @param   string                                                   $identifier  Identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Placeholders.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function message(string $identifier, array $parameters = []): void
    {
    }

    /**
     * Discard one catalogue failure.
     *
     * @param   string                                                   $identifier  Identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Placeholders.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function failure(string $identifier, array $parameters = []): void
    {
    }

    /**
     * Return the identifier itself.
     *
     * @param   string                                                   $identifier  Identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Placeholders.
     *
     * @return  string  The identifier.
     *
     * @since   2.0.0
     */
    public function text(string $identifier, array $parameters = []): string
    {
        return $identifier;
    }

    /**
     * Discard one line.
     *
     * @param   string  $message  Line.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
    }

    /**
     * Discard one failure line.
     *
     * @param   string  $message  Line.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
    }
}
