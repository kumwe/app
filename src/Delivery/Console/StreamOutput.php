<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console;

final readonly class StreamOutput implements Output
{
    /**
     * @param resource $standardOutput
     * @param resource $standardError
     */
    public function __construct(private mixed $standardOutput, private mixed $standardError)
    {
    }

    public static function standard(): self
    {
        return new self(STDOUT, STDERR);
    }

    public function line(string $message): void
    {
        fwrite($this->standardOutput, $message . PHP_EOL);
    }

    public function error(string $message): void
    {
        fwrite($this->standardError, $message . PHP_EOL);
    }
}
