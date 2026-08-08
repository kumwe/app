<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;

/** Bounded database retention entry point for the command idempotency ledger. */
final readonly class BusinessRecordIdempotencyPurger
{
    public function __construct(
        private BusinessRecordIdempotencyRepository $entries,
        private TransactionManager $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function purge(int $limit = 500): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('The idempotency purge batch must contain between 1 and 1000 entries.');
        }

        return $this->transactions->transactional(
            fn (): int => $this->entries->purgeExpired($this->clock->now(), $limit),
        );
    }
}
