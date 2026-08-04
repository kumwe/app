<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console;

interface Command
{
    public function name(): string;

    public function description(): string;

    /**
     * @param list<string> $arguments
     */
    public function execute(array $arguments, Output $output): int;
}
