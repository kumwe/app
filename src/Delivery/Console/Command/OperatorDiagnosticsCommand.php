<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use Kumwe\App\Application\Diagnostics\OperatorDiagnostics;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Token-authorized console access to the shared bounded operator diagnostics.
 *
 * @since  2.0.0
 */
final readonly class OperatorDiagnosticsCommand implements Command
{
    /**
     * Bind the same diagnostic authority the browser and machine adapters call.
     *
     * @param  OperatorDiagnostics  $diagnostics    Authorized bounded reader.
     * @param  ConsoleAuthorizer    $authorization  Site-scoped CLI credential verifier.
     *
     * @since  2.0.0
     */
    public function __construct(private OperatorDiagnostics $diagnostics, private ConsoleAuthorizer $authorization)
    {
    }

    /**
     * Name the read-only operator command.
     *
     * @return  string  Stable command name.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'app:diagnostics';
    }

    /**
     * Supply the localized command-list description.
     *
     * @return  string  Catalogue message identifier.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.diagnostics.description';
    }

    /**
     * Print one bounded JSON snapshot; never accept a credential directly on the command line.
     *
     * @param   list<string>  $arguments  --site, --token-file and optional --section.
     * @param   Output        $output     JSON result or sanitized failure sink.
     *
     * @return  int  Zero for a completed diagnostic, one for a refusal or unavailable source.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, OperatorDiagnostics::CAPABILITY);
            $result = $this->diagnostics->read($context, $options['section'] ?? 'queues');
            $output->line(CommandInput::render($result));

            return $result['status'] === 'available' ? 0 : 1;
        } catch (Throwable $failure) {
            // Console boundary matches the other token-authorized operator commands.
            $output->error($failure->getMessage());

            return 1;
        }
    }
}
