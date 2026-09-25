<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Delivery\Console\Output;

/**
 * Console output sink that keeps raw stdout and stderr lines apart for assertion.
 *
 * @since  2.0.0
 */
final class CapturingMachineConsoleOutput implements Output
{
    /**
     * Ordinary output lines.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $lines = [];

    /**
     * Failure output lines.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $errors = [];

    /**
     * Record one catalogue message identifier as ordinary output.
     *
     * @param   string                                                   $identifier  Message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Unused placeholders.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function message(string $identifier, array $parameters = []): void
    {
        $this->lines[] = $identifier;
    }

    /**
     * Record one catalogue message identifier as failure output.
     *
     * @param   string                                                   $identifier  Message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Unused placeholders.
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
     * Return the identifier itself in place of catalogue wording.
     *
     * @param   string                                                   $identifier  Message identifier.
     * @param   array<string, string|int|float|bool|\DateTimeInterface>  $parameters  Unused placeholders.
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
     * Record one line of ordinary output.
     *
     * @param   string  $message  Line text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    /**
     * Record one line of failure output.
     *
     * @param   string  $message  Line text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function error(string $message): void
    {
        $this->errors[] = $message;
    }
}
