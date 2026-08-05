<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;

final readonly class MigrationStatusCommand implements Command
{
    public function __construct(private MigrationRunner $runner, private SystemPrincipal $system)
    {
    }

    public function name(): string
    {
        return 'database:status';
    }

    public function description(): string
    {
        return 'Show pending Kumwe database migrations.';
    }

    public function execute(array $arguments, Output $output): int
    {
        $pending = $this->runner->pending($this->system->context(
            SiteContext::default(),
            'migration-status-' . bin2hex(random_bytes(16)),
        ));

        if ($pending === []) {
            $output->line('Database schema is current.');

            return 0;
        }

        foreach ($pending as $migration) {
            $output->line(sprintf('Pending %s', $migration->id()));
        }

        return 2;
    }
}
