<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Persistence\ReadinessProbe;

final readonly class HealthCheckCommand implements Command
{
    public function __construct(private ReadinessProbe $probe)
    {
    }

    public function name(): string
    {
        return 'app:health';
    }

    public function description(): string
    {
        return 'Check whether Kumwe is ready to serve traffic.';
    }

    public function execute(array $arguments, Output $output): int
    {
        if ($this->probe->ready()) {
            $output->line('Kumwe is ready.');

            return 0;
        }

        $output->error('Kumwe is not ready.');

        return 1;
    }
}
