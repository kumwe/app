<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\App\BusinessSchema\Application\BusinessSchemaRecoveryEvidenceRecorder;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Console entry point for filing schema recovery evidence as `kumwe business-schema-evidence record`.
 *
 * A data-destroying schema plan can be approved only against a restore drill that was actually performed on the
 * schema it would replace; `business-schema approve --evidence` cites the identifier this command prints. It
 * files through `BusinessSchemaRecoveryEvidenceRecorder`, the use case the administrator schema screen and
 * `POST /api/v1/business-schema-plans/{id}/recovery-evidence` use, so the source-checksum binding, the four
 * clean-target proofs, the environment stamps, `business.schema.recover` and the operator's password re-proof
 * are identical. The password is read from an owner-only `--password-file`, never the process table. The
 * stored evidence is pretty JSON on stdout; any refusal is one line on stderr and exit status 1, as
 * `business-schema` reports.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaEvidenceCommand implements Command
{
    /**
     * Wire the command to the recovery-evidence use case and the console's token authorization route.
     *
     * @param  BusinessSchemaRecoveryEvidenceRecorder  $evidence       Files the drill as the screen does.
     * @param  ConsoleAuthorizer                       $authorization  Resolves `--site` and `--token-file`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSchemaRecoveryEvidenceRecorder $evidence,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the operator types to file recovery evidence.
     *
     * @return  string  Always `business-schema-evidence`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-schema-evidence';
    }

    /**
     * Describe the command for the console's command listing.
     *
     * @return  string  Catalogue identifier of the one-sentence summary.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_schema_evidence.description';
    }

    /**
     * File one drill and print the stored evidence, or one error line.
     *
     * @param   list<string>  $arguments  `record`, then `--plan`, `--proofs` (comma-separated proof names),
     *          `--backup-manifest-checksum`, `--backup-created-at`, `--verified-at`, `--drill-reference`,
     *          `--client-version`, `--restore-target-reference`, `--password-file`, `--site` and `--token-file`.
     * @param   Output        $output     Sink for the JSON result or the failure line.
     *
     * @return  int  0 when the evidence was filed, 1 when any step failed.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            if (array_shift($arguments) !== 'record') {
                throw new InvalidArgumentException('Unsupported business-schema-evidence action.');
            }
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'business.schema.recover');
            $evidence = $this->evidence->record(
                $context,
                CommandInput::required($options, 'plan'),
                array_values(array_filter(array_map(
                    'trim',
                    explode(',', CommandInput::required($options, 'proofs')),
                ), static fn (string $proof): bool => $proof !== '')),
                CommandInput::required($options, 'backup-manifest-checksum'),
                self::date($options, 'backup-created-at'),
                self::date($options, 'verified-at'),
                CommandInput::required($options, 'drill-reference'),
                CommandInput::required($options, 'client-version'),
                CommandInput::required($options, 'restore-target-reference'),
                CommandInput::secretFile(CommandInput::required($options, 'password-file')),
            );
            $output->line(CommandInput::render($evidence->toArray()));

            return 0;
        } catch (Throwable $exception) {
            // The console boundary: every refusal leaves as one operator-readable line and exit status 1.
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Read one required timestamp option, parsed as the schema screen parses its date fields.
     *
     * @param   array<string, string>  $options  Parsed options.
     * @param   string                 $name     Option name.
     *
     * @return  DateTimeImmutable  Parsed instant.
     *
     * @throws  InvalidArgumentException  When the option is missing or unreadable.
     *
     * @since   2.0.0
     */
    private static function date(array $options, string $name): DateTimeImmutable
    {
        $value = CommandInput::required($options, $name);
        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(sprintf('The --%s option is invalid.', $name), 0, $exception);
        }
    }
}
