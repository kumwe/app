<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;

/**
 * Stable, disclosure-safe failure returned by a business console adapter.
 *
 * The process exit code and JSON body are kept together so a command cannot accidentally print one
 * classification while returning another. Messages are chosen by `BusinessConsoleFailureMapper`, never
 * copied blindly from an arbitrary throwable, and details are limited to bounded structured evidence such
 * as validation codes and optimistic versions.
 *
 * @since  2.0.0
 */
final readonly class BusinessConsoleFailure
{
    /**
     * Capture one safe failure classification.
     *
     * @param   int                   $exitCode  Process status in the portable 1-255 range.
     * @param   string                $code      Stable lowercase dotted error identifier.
     * @param   string                $message   Operator-facing sentence carrying no submitted value.
     * @param   array<string, mixed>  $details   Optional bounded structured evidence.
     *
     * @throws  InvalidArgumentException  When the exit code, stable code, message or detail shape is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public int $exitCode,
        public string $code,
        public string $message,
        public array $details = [],
    ) {
        if (
            $exitCode < 1 || $exitCode > 255
            || preg_match('/^[a-z][a-z0-9._-]{0,126}$/D', $code) !== 1
            || $message === ''
            || strlen($message) > 500
            || count($details) > 32
        ) {
            throw new InvalidArgumentException('A business console failure is malformed or unbounded.');
        }
    }

    /**
     * Render the error member of the command's stable JSON envelope.
     *
     * @return  array<string, mixed>  Code, message and optional structured details.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        /** @var array<string, mixed> $error */
        $error = ['code' => $this->code, 'message' => $this->message];
        if ($this->details !== []) {
            $error['details'] = $this->details;
        }

        return $error;
    }
}
