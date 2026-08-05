<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Presentation\Application\AdministratorThemeRecovery;
use Throwable;

final readonly class RecoverAdministratorThemeCommand implements Command
{
    public function __construct(private AdministratorThemeRecovery $recovery)
    {
    }

    public function name(): string
    {
        return 'theme:administrator:recover';
    }

    public function description(): string
    {
        return 'Atomically restore the protected built-in administrator theme.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        try {
            if ($arguments !== ['--confirm=restore-core-administrator']) {
                throw new InvalidArgumentException(
                    'Recovery requires --confirm=restore-core-administrator.',
                );
            }

            $this->recovery->recover();
            $output->line('Restored the protected built-in administrator theme.');

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
