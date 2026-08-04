<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;

final readonly class MigrateCommand implements Command
{
    public function __construct(private MigrationRunner $runner)
    {
    }

    public function name(): string
    {
        return 'database:migrate';
    }

    public function description(): string
    {
        return 'Apply pending forward-only Kumwe 2.x migrations.';
    }

    public function execute(array $arguments, Output $output): int
    {
        $result = $this->runner->migrate();

        if (!$result->changed()) {
            $output->line('Database schema is current.');

            return 0;
        }

        foreach ($result->applied as $migration) {
            $output->line(sprintf('Applied %s', $migration));
        }

        return 0;
    }
}
