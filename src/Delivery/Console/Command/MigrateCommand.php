<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Runtime\ExtensionRuntimeMapCompiler;
use Kumwe\CMS\Infrastructure\Persistence\Migration\MigrationRunner;

final readonly class MigrateCommand implements Command
{
    public function __construct(
        private MigrationRunner $runner,
        private ExtensionRuntimeMapCompiler $extensions,
        private SystemPrincipal $system,
    ) {
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
        $result = $this->runner->migrate($this->system->context(
            SiteContext::default(),
            'migration-' . bin2hex(random_bytes(16)),
        ));

        if (!$result->changed()) {
            $output->line('Database schema is current; reconciling the extension runtime publication.');
        } else {
            foreach ($result->applied as $migration) {
                $output->line(sprintf('Applied %s', $migration));
            }
        }
        $state = $this->extensions->reconcileAndMaterialize(true);
        $output->line(sprintf('Materialized extension runtime generation %d', $state->generation));

        return 0;
    }
}
