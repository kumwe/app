<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Joomla\Database\DatabaseInterface;
use Throwable;

final readonly class JoomlaTransactionManager implements TransactionManager
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $this->database->transactionStart();

        try {
            $result = $operation();
            $this->database->transactionCommit();

            return $result;
        } catch (Throwable $exception) {
            $this->database->transactionRollback();
            throw $exception;
        }
    }
}
