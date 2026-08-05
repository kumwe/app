<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Application\Operations\MigrationLockRecoveryService;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;

final readonly class RecoverMigrationLockCommand implements Command
{
    public function __construct(
        private MigrationLockRecoveryService $recovery,
        private SystemPrincipal $system,
    ) {
    }

    public function name(): string
    {
        return 'database:recover-lock';
    }

    public function description(): string
    {
        return 'CAS-remove one expired legacy migration owner after quiescing every older binary.';
    }

    public function execute(array $arguments, Output $output): int
    {
        $expectedOwner = null;
        $confirmed = false;
        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '--expected-owner=')) {
                $expectedOwner = substr($argument, strlen('--expected-owner='));
            } elseif ($argument === '--confirm-legacy-quiesced') {
                $confirmed = true;
            } else {
                $output->error(sprintf('Unknown database:recover-lock argument: %s', $argument));

                return 64;
            }
        }
        if (!is_string($expectedOwner) || $expectedOwner === '' || !$confirmed) {
            $output->error(
                'Usage: database:recover-lock --expected-owner=<64-hex-token> --confirm-legacy-quiesced',
            );

            return 64;
        }

        $this->recovery->recover(
            $this->system->context(SiteContext::default(), 'migration-lock-recovery-' . bin2hex(random_bytes(16))),
            $expectedOwner,
            true,
        );
        $output->line('Expired legacy migration owner removed. Run database:migrate immediately.');

        return 0;
    }
}
