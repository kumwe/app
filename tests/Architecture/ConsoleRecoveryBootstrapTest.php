<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins the console bootstrap's recovery list to command names the console actually registers.
 *
 * `bootstrap/console.php` decides, by command name, whether `bin/kumwe` boots the reduced recovery
 * container or the full one. A name in that list that no command answers to is not an error anywhere:
 * the command it was meant to protect simply boots the full container, and the first time that matters
 * is the incident in which the full container cannot be built. The retained CLI contract is the one
 * inventory of registered names, so every recovery entry must appear in it, and the break-glass
 * commands the file exists for must be in the list.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ConsoleRecoveryBootstrapTest extends TestCase
{
    /**
     * Every recovery-list entry names a registered command, and the break-glass commands are all listed.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryRecoveryCommandNameIsARegisteredConsoleCommand(): void
    {
        $root = dirname(__DIR__, 2);
        $bootstrap = file_get_contents($root . '/bootstrap/console.php');
        self::assertIsString($bootstrap);
        self::assertSame(
            1,
            preg_match('/\$recoveryCommands\s*=\s*\[(?<entries>.*?)\];/s', $bootstrap, $match),
            'The console bootstrap must declare its recovery list as one literal array.',
        );
        preg_match_all('/\'(?<name>[a-z][a-z0-9:-]*)\'/', $match['entries'], $entries);
        $recovery = $entries['name'];
        self::assertNotSame([], $recovery);

        $contract = json_decode(
            (string) file_get_contents($root . '/docs/machine-contract/cli-v1.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($contract);
        $registered = [];
        foreach ($contract['commands'] ?? [] as $command) {
            if (is_array($command) && is_string($command['name'] ?? null)) {
                $registered[] = $command['name'];
            }
        }

        foreach ($recovery as $name) {
            self::assertContains($name, $registered, sprintf(
                'The recovery list names "%s", which no registered console command answers to.',
                $name,
            ));
        }
        foreach (
            [
                'app:health',
                'database:migrate',
                'database:recover-lock',
                'database:status',
                'extension:runtime:materialize',
                'theme:administrator:recover',
                'user:create-admin',
                'user:recover-credentials',
            ] as $breakGlass
        ) {
            self::assertContains($breakGlass, $recovery, sprintf(
                'The break-glass command "%s" must boot the recovery container.',
                $breakGlass,
            ));
        }
    }
}
