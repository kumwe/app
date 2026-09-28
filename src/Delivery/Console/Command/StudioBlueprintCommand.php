<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Kumwe\App\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionGateway;
use Kumwe\App\Studio\Application\Authoring\StudioMachineCompositionOperation;

/**
 * Console binding of Blueprint composition editing: `bin/kumwe studio-blueprint ACTION`.
 *
 * `open` opens a Blueprint session bound to the token file's credential for one provisioned Content type
 * version; the five other actions are the `artifact` operations the administrator composition screen's Studio
 * shell dispatches. Every operation argument arrives from an owner-only protected JSON file, never the process
 * table; a mutating action's `--operation-id` becomes the Studio host's replay key and its `--expected-revision`
 * the revision the host fences on. Success prints `{"ok":true,"data":...,"meta":{...}}`; every refusal prints
 * the same category, diagnostics and revision the browser's `host-error` carries and exits with the portable
 * status `studio-authoring` uses.
 *
 * @since  2.0.0
 */
final readonly class StudioBlueprintCommand implements Command
{
    /**
     * Portable process status for each Producer refusal category, shared with `studio-authoring`.
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
     * Bind the command to the composition gateway and the credential authorizer.
     *
     * @param  StudioMachineCompositionGateway  $compositions   Machine entry to the composition screen's host.
     * @param  ConsoleAuthorizer                $authorization  Site- and token-file-bound CLI authenticator.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioMachineCompositionGateway $compositions,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `studio-blueprint`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'studio-blueprint';
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
        return 'core.console.studio_blueprint.description';
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
            $context = $this->authorization->require($options, 'content.read');
            if ($action === 'open') {
                $mode = $options['mode'] ?? 'blueprint';
                if (!in_array($mode, ['blueprint', 'read-only'], true)) {
                    throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/target-invalid');
                }
                $session = $this->compositions->open(
                    $context,
                    CommandInput::required($options, 'content-type'),
                    CommandInput::positiveInteger($options, 'content-type-version'),
                    $mode === 'read-only',
                );
                $output->line(CommandInput::render([
                    'ok' => true,
                    'data' => $session->toDocument(),
                    'meta' => ['action' => 'open', 'surface' => 'cli'],
                ]));

                return 0;
            }
            $operation = StudioMachineCompositionOperation::named($action);
            $result = $this->compositions->perform(
                $context,
                $operation,
                CommandInput::required($options, 'session'),
                CommandInput::required($options, 'session-generation'),
                CommandInput::protectedJsonDocument(CommandInput::required($options, 'argument-file')),
                $options['expected-revision'] ?? null,
                $options['operation-id'] ?? null,
                $options['locale'] ?? null,
            );
            $document = $result->toDocument();
            $output->line(CommandInput::render([
                'ok' => true,
                'data' => ['value' => $document->value, 'revision' => $document->revision],
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
                'message' => $output->text('core.console.studio_blueprint.refused'),
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
