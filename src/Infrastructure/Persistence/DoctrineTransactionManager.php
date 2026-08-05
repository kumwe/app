<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

final class DoctrineTransactionManager implements TransactionManager
{
    /** @var list<array{commit: list<callable(): void>, rollback: list<callable(): void>}> */
    private array $frames = [];

    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        $this->frames[] = ['commit' => [], 'rollback' => []];
        try {
            $result = $this->connection->transactional(
                static fn (Connection $connection): mixed => $operation(),
            );
        } catch (\Throwable $exception) {
            $frame = array_pop($this->frames);
            if (is_array($frame)) {
                $this->invoke($frame['rollback']);
            }
            throw $exception;
        }

        $frame = array_pop($this->frames);
        if (!is_array($frame)) {
            throw new \LogicException('The transaction completion frame was lost.');
        }
        $parent = array_key_last($this->frames);
        if ($parent !== null) {
            array_push($this->frames[$parent]['commit'], ...$frame['commit']);
            array_push($this->frames[$parent]['rollback'], ...$frame['rollback']);
        } else {
            $this->invoke($frame['commit']);
        }

        return $result;
    }

    public function afterCommit(callable $operation): void
    {
        $frame = array_key_last($this->frames);
        if ($frame === null) {
            $operation();
            return;
        }
        $this->frames[$frame]['commit'][] = $operation;
    }

    public function afterRollback(callable $operation): void
    {
        $frame = array_key_last($this->frames);
        if ($frame !== null) {
            $this->frames[$frame]['rollback'][] = $operation;
        }
    }

    /** @param list<callable(): void> $operations */
    private function invoke(array $operations): void
    {
        foreach ($operations as $operation) {
            $operation();
        }
    }
}
