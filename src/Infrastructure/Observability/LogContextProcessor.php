<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps every log record with the context the observability contract declares as required.
 *
 * `config/observability.php` states that a record carries `correlation_id`, `release`, `runtime` and
 * `outcome`. Before this processor existed that was an unenforced claim: the release lived in
 * configuration, the correlation identifier lived on the request, and neither reached a log line. Now
 * the contract's `required_context` list is the loop this processor runs, so adding a key to the
 * declaration adds it to every record and a key the runtime cannot supply is visible as `unknown`
 * rather than silently absent.
 *
 * `outcome` is derived from the record's own severity when the caller did not state one, which makes
 * every line answer "did this work?" without asking three dozen call sites to say so explicitly. A
 * caller that knows better — a dispatcher that deferred rather than failed — still wins, because an
 * explicitly supplied value is never overwritten.
 *
 * @since  2.0.0
 */
final readonly class LogContextProcessor implements ProcessorInterface
{
    /**
     * Placeholder written when the runtime cannot supply a declared context key.
     *
     * An explicit `unknown` is deliberately preferable to omitting the key: a log query that filters on
     * `correlation_id` should show that a line had none, not silently drop it from the result.
     *
     * @var    string
     * @since  2.0.0
     */
    public const UNKNOWN = 'unknown';

    /**
     * Bind the processor to the values it stamps onto records.
     *
     * @param  ObservabilityContract  $contract     Declaration whose `required_context` list drives the stamping.
     * @param  CorrelationContext     $correlation  Holder the current unit of work publishes its identifiers on.
     * @param  string                 $release      Immutable release identifier of this deployment.
     * @param  string                 $runtime      Surface this process serves, such as `http`, `console` or `mcp`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ObservabilityContract $contract,
        private CorrelationContext $correlation,
        private string $release,
        private string $runtime,
    ) {
    }

    /**
     * Merge the declared required context, and the current identifiers, into the record.
     *
     * @param   LogRecord  $record  Record on its way to the handler.
     *
     * @return  LogRecord  The record with contract context applied; caller-supplied keys always win.
     *
     * @since   2.0.0
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        foreach ($this->correlation->fragment() as $key => $value) {
            $context[$key] ??= $value;
        }
        foreach ($this->contract->requiredContext as $key) {
            $context[$key] ??= $this->declared($key, $record);
        }

        return $record->with(context: $context);
    }

    /**
     * Resolve one declared required-context key for this record.
     *
     * @param   string     $key     Declared context key being filled in.
     * @param   LogRecord  $record  Record whose severity decides a derived outcome.
     *
     * @return  string  The value to stamp; `unknown` when nothing in the runtime supplies the key.
     *
     * @since   2.0.0
     */
    private function declared(string $key, LogRecord $record): string
    {
        return match ($key) {
            'correlation_id' => $this->correlation->correlationId() ?? self::UNKNOWN,
            'release' => $this->release,
            'runtime' => $this->runtime,
            'outcome' => $record->level->value >= 300 ? 'failure' : 'success',
            default => self::UNKNOWN,
        };
    }
}
