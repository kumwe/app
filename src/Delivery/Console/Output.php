<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console;

interface Output
{
    public function line(string $message): void;

    public function error(string $message): void;
}
