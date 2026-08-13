<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\SyntaxErrorException;
use Doctrine\DBAL\Query;

/**
 * Test-only connection that answers `CREATE TRIGGER` the way a managed MySQL without `SUPER` answers it.
 *
 * The failure this stands in for is real and was reproduced against a live MariaDB with binary logging
 * enabled and a non-`SUPER` account, which raises error 1419 exactly as the CI MySQL 8.4 job does. What
 * cannot be reproduced portably is the *server configuration*: a test suite may not assume it can enable
 * binary logging or revoke privileges on whatever database it was pointed at. Wiring the refusal in at
 * the driver seam through DBAL's supported `wrapperClass` hook reproduces the one thing the code under
 * test actually sees — the exception, its class, its SQLSTATE and its driver code — on every platform
 * and with no privileges of any kind.
 *
 * Setting `$privilegeRefusal` to false swaps the privilege error for a syntax error, which is how a test
 * proves the degradation path is narrow: that failure must still abort the migration.
 *
 * Only trigger creation is affected. Everything else runs against the real database underneath, so a
 * test using this exercises the genuine migration, the genuine recorder and the genuine verifier.
 */
final class TriggerRefusingConnection extends Connection
{
    /** Whether trigger creation fails as a privilege refusal (true) or as a broken statement (false). */
    public bool $privilegeRefusal = true;

    /** Fails trigger creation with a driver error, and passes every other statement through. */
    public function executeStatement(string $sql, array $params = [], array $types = []): int|string
    {
        if (!str_starts_with(ltrim($sql), 'CREATE TRIGGER')) {
            return parent::executeStatement($sql, $params, $types);
        }
        if (!$this->privilegeRefusal) {
            throw new SyntaxErrorException(
                new PdoDriverException('You have an error in your SQL syntax', '42000', 1064),
                new Query($sql, [], []),
            );
        }

        throw new DriverException(
            new PdoDriverException(
                'SQLSTATE[HY000]: General error: 1419 You do not have the SUPER privilege and binary '
                . 'logging is enabled (you *might* want to use the less safe '
                . 'log_bin_trust_function_creators variable)',
                'HY000',
                1419,
            ),
            new Query($sql, [], []),
        );
    }
}
