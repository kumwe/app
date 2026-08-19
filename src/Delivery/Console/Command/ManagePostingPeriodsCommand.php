<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Command;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Application\PostingPeriodService;
use Kumwe\App\BusinessRecord\Domain\PostingPeriod;
use Kumwe\App\Delivery\Console\Command;
use Kumwe\App\Delivery\Console\Output;
use Throwable;

/**
 * Drives posting-period administration — list, close, re-open — from a host-authorized shell.
 *
 * The command is a thin adapter over `PostingPeriodService`, which is where the capability gate and
 * the audit entry live, so shell administration cannot diverge from the REST surface. Listing and
 * managing are independently grantable: a reporting token can be given `business.period.read` without
 * ever being able to close a range. Ranges are half-open — `--ends` names the first instant past the
 * period — and a bare date is read as that day's first UTC midnight.
 *
 * @since  2.0.0
 */
final readonly class ManagePostingPeriodsCommand implements Command
{
    /**
     * Capability each action demands, keyed by the action name an operator types.
     *
     * The map doubles as the list of supported actions — an action missing from it is refused before
     * any capability is checked and before any declaration is read.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const CAPABILITIES = [
        'list' => PostingPeriodService::READ,
        'close' => PostingPeriodService::MANAGE,
        'reopen' => PostingPeriodService::MANAGE,
    ];

    /**
     * Wire the period service and the gate that turns console options into an authorized actor.
     *
     * @param  PostingPeriodService  $periods        Owns closing, re-opening and listing, with their
     *         capability gates and audit entries.
     * @param  ConsoleAuthorizer     $authorization  Turns `--site` and `--token-file` into an execution
     *         context carrying the capability the requested action demands.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PostingPeriodService $periods,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    /**
     * Name the console dispatcher registers this command under.
     *
     * @return  string  Always `business-periods`.
     *
     * @since   2.0.0
     */
    public function name(): string
    {
        return 'business-periods';
    }

    /**
     * Summary line `bin/kumwe list` prints beside the command name.
     *
     * @return  string  One-sentence statement of the period administration this command covers.
     *
     * @since   2.0.0
     */
    public function description(): string
    {
        return 'core.console.business_periods.description';
    }

    /**
     * Dispatch one posting-period action and print its result as JSON.
     *
     * The first argument names the action and defaults to `list`; the rest are `--name=value`
     * options. `close` takes `--key`, `--starts` and `--ends`; `reopen` takes `--key`; both accept
     * `--organization` to address an organization-scoped declaration instead of a site-wide one.
     *
     * @param   list<string>  $arguments  Action name first, then `--name=value` options.
     * @param   Output        $output     Sink for the JSON result, or for the failure message.
     *
     * @return  int  `0` when the action completed, `1` with its message on stderr when it did not.
     *
     * @since   2.0.0
     */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $capability = self::CAPABILITIES[$action]
                ?? throw new InvalidArgumentException('Unsupported business-periods action.');
            $context = $this->authorization->require($options, $capability);
            $organization = $options['organization'] ?? null;

            $result = match ($action) {
                'list' => ['items' => array_map(
                    static fn (PostingPeriod $period): array => $period->toArray(),
                    $this->periods->list($context, $organization),
                )],
                'close' => $this->periods->close(
                    $context,
                    CommandInput::required($options, 'key'),
                    $this->instant(CommandInput::required($options, 'starts'), 'starts'),
                    $this->instant(CommandInput::required($options, 'ends'), 'ends'),
                    $organization,
                )->toArray(),
                default => $this->periods->reopen(
                    $context,
                    CommandInput::required($options, 'key'),
                    $organization,
                )->toArray(),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * Parse one boundary option into the UTC instant the declaration stores.
     *
     * @param   string  $value  Either a bare `YYYY-MM-DD`, read as that day's first UTC midnight, or a
     *          full RFC 3339 UTC instant such as `2026-08-01T00:00:00Z`.
     * @param   string  $name   Option name, used to say which boundary was malformed.
     *
     * @return  DateTimeImmutable  The parsed instant in UTC.
     *
     * @throws  InvalidArgumentException  When the value parses as neither shape.
     *
     * @since   2.0.0
     */
    private function instant(string $value, string $name): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);
        if ($date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value) {
            return $date;
        }
        if (
            preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|\+00:00)$/D',
                $value,
            ) === 1
        ) {
            try {
                return (new DateTimeImmutable($value))->setTimezone($utc);
            } catch (\Exception $malformed) {
                throw new InvalidArgumentException(sprintf(
                    'The --%s option is not a valid instant.',
                    $name,
                ), 0, $malformed);
            }
        }

        throw new InvalidArgumentException(sprintf(
            'The --%s option must be a YYYY-MM-DD date or an RFC 3339 UTC instant.',
            $name,
        ));
    }
}
