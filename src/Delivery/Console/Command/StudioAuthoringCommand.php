<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringOperation;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;

/**
 * Console binding of Studio's contextual authoring operations: `bin/kumwe studio-authoring ACTION`.
 *
 * `open` opens a session bound to the token file's credential for one exact create or edit target; the seven
 * other actions are the operations the administrator browser dispatches. Every operation argument arrives
 * from an owner-only protected JSON file, never the process table, and a mutating action's
 * `--operation-id` becomes the Studio host's replay key. Success prints
 * `{"ok":true,"data":...,"meta":{...}}`; every refusal prints the same category, diagnostics and revision
 * the browser's `host-error` carries and exits with a portable status.
 *
 * @since  2.0.0
 */
final readonly class StudioAuthoringCommand implements Command
{
    /**
     * Capability the token must hold before any Studio authority is consulted.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string FLOOR_CAPABILITY = 'content.read';

    /**
     * Portable process status for each Producer refusal category.
     *
     * @var    array<string, int>
     * @since  2.0.0
     */
    private const array EXITS = [
        'invalid-request' => 65,
        'incompatible' => 65,
        'cancelled' => 65,
        'validation-failed' => 65,
        'limit-exceeded' => 65,
        'unauthenticated' => 77,
        'forbidden' => 77,
        'not-found' => 66,
        'conflict' => 73,
        'rate-limited' => 75,
        'unavailable' => 69,
        'internal' => 1,
    ];

    /**
     * Bind the command to the authoring gateway and the credential authorizer.
     *
     * @param  StudioMachineAuthoringGateway  $authoring      Machine entry to the browser's Studio host.
     * @param  ConsoleAuthorizer              $authorization  Site- and token-file-bound CLI authenticator.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioMachineAuthoringGateway $authoring,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `studio-authoring`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'studio-authoring';
    }

    /**
     * Name the catalogue message the command listing prints beside this command.
     *
     * @return  string  Stable message identifier of the one-line summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.studio_authoring.description';
    }

    /**
     * Run one open or operation action and print its JSON envelope.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options.
     * @param   Output        $output     Sink for the success envelope, or the failure envelope.
     *
     * @return  int  `0` on success; 65, 66, 69, 73, 75, 77 or 1 by refusal category.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        $action = array_shift($arguments) ?? '';
        try {
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, self::FLOOR_CAPABILITY);
            if ($action === 'open') {
                $intent = StudioAuthoringIntent::tryFrom($options['intent'] ?? '')
                    ?? throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
                $version = isset($options['content-type-version'])
                    ? CommandInput::positiveInteger($options, 'content-type-version')
                    : null;
                $session = $this->authoring->open(
                    $context,
                    $intent,
                    $options['content'] ?? null,
                    $options['content-type'] ?? null,
                    $version,
                );
                $output->line(CommandInput::render([
                    'ok' => true,
                    'data' => $session->toDocument(),
                    'meta' => ['action' => 'open', 'surface' => 'cli'],
                ]));

                return 0;
            }
            $operation = StudioMachineAuthoringOperation::named($action);
            $argument = CommandInput::protectedJsonDocument(CommandInput::required($options, 'argument-file'));
            $result = $this->authoring->perform(
                $context,
                $operation,
                CommandInput::required($options, 'session'),
                CommandInput::required($options, 'session-generation'),
                $argument,
                $options['operation-id'] ?? null,
                $options['locale'] ?? null,
            );
            $output->line(CommandInput::render([
                'ok' => true,
                'data' => $result->value(),
                'meta' => ['action' => $operation->value, 'surface' => 'cli', 'replayed' => $result->replayed],
            ]));

            return 0;
        } catch (StudioMachineAuthoringRefused $refused) {
            return self::fail($output, $refused);
        } catch (InsufficientCapability) {
            return self::fail(
                $output,
                StudioMachineAuthoringRefused::of('forbidden', 'studio.machine/credential-refused'),
            );
        } catch (InvalidArgumentException | JsonException) {
            return self::fail(
                $output,
                StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/request-invalid'),
            );
        }
    }

    /**
     * Print one refusal envelope and return its portable status.
     *
     * @param   Output                         $output   Sink the failure envelope is written to.
     * @param   StudioMachineAuthoringRefused  $refused  Canonical Studio refusal.
     *
     * @return  int  Process status paired with the refusal.
     *
     * @since   2.0.0
     */
    private static function fail(Output $output, StudioMachineAuthoringRefused $refused): int
    {
        $details = ['category' => $refused->category(), 'diagnostics' => $refused->diagnosticCodes()];
        if ($refused->revision() !== null) {
            $details['revision'] = $refused->revision();
        }
        $output->error(CommandInput::render([
            'ok' => false,
            'error' => [
                'code' => $refused->stableCode(),
                'message' => $output->text('core.console.studio_authoring.refused'),
                'details' => $details,
            ],
        ]));
        if ($refused->keyReused()) {
            return 73;
        }
        if ($refused->inProgress()) {
            return 75;
        }

        return self::EXITS[$refused->category()] ?? 1;
    }
}
