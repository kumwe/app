<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Enforces the declared redaction list on everything a record carries before it reaches a handler.
 *
 * Redaction used to live only in the domain and presentation layers, which left the diagnostic log as
 * the one path where a credential could still escape: a warning that attaches an exception object
 * publishes whatever the driver put in its message, and a driver's message routinely quotes the DSN it
 * failed to connect with. This processor closes both holes at the last point before formatting — the
 * only place that sees every record from every subsystem.
 *
 * Two rules, both driven by `config/observability.php`:
 *
 * - any context or extra key whose name contains a declared redacted field loses its value entirely;
 * - any `Throwable` is replaced by a bounded summary whose message has been scrubbed of URI credentials
 *   and of `key=value` pairs naming a redacted field, and which carries no stack trace at all.
 *
 * Dropping the trace is a deliberate trade. A trace is the most useful thing in an exception and also
 * the most dangerous: frame arguments can hold the very secrets the first rule just removed. The class,
 * message, file and line survive, which is what an operator actually greps for; the full trace belongs
 * in a debugger against a reproduction, not in a shipped log stream.
 *
 * @since  2.0.0
 */
final readonly class LogRedactionProcessor implements ProcessorInterface
{
    /**
     * Value written in place of anything the contract refuses to publish.
     *
     * @var    string
     * @since  2.0.0
     */
    public const PLACEHOLDER = '[redacted]';

    /**
     * Longest exception message retained, in characters.
     *
     * A driver may return a message holding an entire failed statement. Truncating bounds both the log
     * volume and the amount of quoted payload that can ride along in it.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MESSAGE_LIMIT = 512;

    /**
     * Deepest nesting walked inside a context value.
     *
     * Structures below this are replaced wholesale rather than inspected, so a pathological payload
     * cannot turn one log line into an unbounded traversal on a hot path.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_DEPTH = 6;

    /**
     * Bind the processor to the declaration that decides what may not be published.
     *
     * @param  ObservabilityContract  $contract  Declaration whose `redacted_fields` list is enforced.
     *
     * @since  2.0.0
     */
    public function __construct(private ObservabilityContract $contract)
    {
    }

    /**
     * Replace every refused value in the record's context and extra.
     *
     * @param   LogRecord  $record  Record on its way to the handler.
     *
     * @return  LogRecord  The record with refused values replaced and exceptions summarised.
     *
     * @since   2.0.0
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->walk($record->context, 0),
            extra: $this->walk($record->extra, 0),
        );
    }

    /**
     * Walk one array level, applying the key rule and the exception rule.
     *
     * @param   array<mixed, mixed>  $values  Values at this level.
     * @param   int                  $depth   Current nesting depth.
     *
     * @return  array<mixed, mixed>  The same shape with refused values replaced.
     *
     * @since   2.0.0
     */
    private function walk(array $values, int $depth): array
    {
        $walked = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && $this->contract->redactsKey($key)) {
                $walked[$key] = self::PLACEHOLDER;
                continue;
            }
            $walked[$key] = $this->value($value, $depth);
        }

        return $walked;
    }

    /**
     * Reduce one value to something the contract allows to be published.
     *
     * @param   mixed  $value  Value carried by the record.
     * @param   int    $depth  Current nesting depth.
     *
     * @return  mixed  The value, an exception summary, or a placeholder.
     *
     * @since   2.0.0
     */
    private function value(mixed $value, int $depth): mixed
    {
        if ($value instanceof Throwable) {
            return $this->summarise($value, $depth);
        }
        if (is_array($value)) {
            return $depth >= self::MAX_DEPTH ? self::PLACEHOLDER : $this->walk($value, $depth + 1);
        }

        return $value;
    }

    /**
     * Summarise a throwable into the bounded, scrubbed shape a log may carry.
     *
     * @param   Throwable  $failure  Exception attached to the record.
     * @param   int        $depth    Current nesting depth, which also bounds the `previous` chain.
     *
     * @return  array<string, mixed>  Class, scrubbed message, code, file, line and any previous summary.
     *
     * @since   2.0.0
     */
    private function summarise(Throwable $failure, int $depth): array
    {
        $summary = [
            'class' => $failure::class,
            'message' => $this->scrub($failure->getMessage()),
            'code' => $failure->getCode(),
            'file' => $failure->getFile(),
            'line' => $failure->getLine(),
        ];
        $previous = $failure->getPrevious();
        if ($previous !== null && $depth < self::MAX_DEPTH) {
            $summary['previous'] = $this->summarise($previous, $depth + 1);
        }

        return $summary;
    }

    /**
     * Strip credential-shaped fragments out of a free-text message and bound its length.
     *
     * Two shapes cover what actually leaks in practice: the userinfo section of a connection URI, and
     * an assignment naming one of the declared redacted fields. Neither pattern is a general secret
     * detector — the key rule above is the contract, and this is the belt for the one value that is
     * free text by nature.
     *
     * @param   string  $message  Raw exception message.
     *
     * @return  string  The message with credential fragments replaced and its length bounded.
     *
     * @since   2.0.0
     */
    private function scrub(string $message): string
    {
        $scrubbed = (string) preg_replace(
            '#(?<=://)[^/\s:@]+:[^/\s@]+(?=@)#',
            self::PLACEHOLDER,
            $message,
        );
        foreach ($this->contract->redactedFields as $field) {
            $scrubbed = (string) preg_replace(
                sprintf('#(%s[A-Za-z_-]*\s*[=:]\s*)(?:"[^"]*"|\'[^\']*\'|[^\s,;)&]+)#i', preg_quote($field, '#')),
                '$1' . self::PLACEHOLDER,
                $scrubbed,
            );
        }
        if (mb_strlen($scrubbed) > self::MESSAGE_LIMIT) {
            return mb_substr($scrubbed, 0, self::MESSAGE_LIMIT) . '…';
        }

        return $scrubbed;
    }
}
