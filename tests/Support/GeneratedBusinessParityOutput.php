<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Kumwe\CMS\Delivery\Console\Output;

/**
 * Captures the stable JSON output of one generated-business command invocation.
 *
 * @since  2.0.0
 */
final class GeneratedBusinessParityOutput implements Output
{
    /**
     * Success lines captured from standard output.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $lines = [];

    /**
     * Failure lines captured from standard error.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $errors = [];

    /**
     * Capture one success line.
     *
     * @param   string  $message  JSON success envelope.
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
     * Capture one error line.
     *
     * @param   string  $message  JSON failure envelope.
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
