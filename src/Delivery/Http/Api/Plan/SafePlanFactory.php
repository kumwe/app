<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Plan;

use DateInterval;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

/**
 * Mints `SafePlan` documents, giving each one its identifier and its validity window.
 *
 * `SafePlan` refuses anything malformed but decides nothing; this is where a plan's identity and expiry
 * actually come from. The identifier is a UUIDv7 derived from the creation instant, so plan identifiers
 * sort in the order they were minted and satisfy the value object's canonical-UUIDv7 rule. The lifetime
 * is fixed at construction and range-checked once, which is what stops a request from choosing how long
 * its own preview stays valid; the container wires the default of fifteen minutes. The clock is injected
 * so the window a test asserts on is the window it pinned.
 *
 * @since  2.0.0
 */
final readonly class SafePlanFactory
{
    /**
     * Fix the time source and the lifetime every plan this factory mints will carry.
     *
     * @param   ClockInterface  $clock       Source of the creation instant; both the identifier and the
     *          validity window derive from it.
     * @param   int             $ttlSeconds  How long a minted plan stays valid, from 60 seconds to an hour.
     *
     * @throws  \InvalidArgumentException  When the lifetime falls outside 60 to 3600 seconds.
     *
     * @since   2.0.0
     */
    public function __construct(
        private ClockInterface $clock,
        private int $ttlSeconds = 900,
    ) {
        if ($ttlSeconds < 60 || $ttlSeconds > 3_600) {
            throw new \InvalidArgumentException('A safe plan TTL must be between 60 and 3600 seconds.');
        }
    }

    /**
     * Mint a plan for one review, valid from the current instant until the configured lifetime elapses.
     *
     * The target is trimmed on the way in, so surrounding whitespace never counts against the length and
     * emptiness rules; every other judgement about the target belongs to `SafePlan`, which is what turns
     * an unusable one into the refusal the plan route renders as a 400.
     *
     * @param   SafePlanOperation  $operation  Which review the plan is to describe.
     * @param   string             $target     What the review is about, as the caller named it.
     *
     * @return  SafePlan  A fresh plan whose identifier encodes the instant it was minted.
     *
     * @throws  \InvalidArgumentException  When the trimmed target is empty, longer than 255 bytes, or
     *          carries a control character.
     *
     * @since   2.0.0
     */
    public function create(SafePlanOperation $operation, string $target): SafePlan
    {
        $createdAt = $this->clock->now();

        return new SafePlan(
            Uuid::uuid7($createdAt)->toString(),
            $operation,
            trim($target),
            $createdAt,
            $createdAt->add(new DateInterval(sprintf('PT%dS', $this->ttlSeconds))),
        );
    }
}
