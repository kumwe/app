<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use LogicException;

/** Process-local nesting state for database platforms whose durable advisory-lock fallback is not reentrant. */
final class ReentrantLifecycleLock
{
    private int $depth = 0;

    public function held(): bool
    {
        return $this->depth > 0;
    }

    public function enter(): void
    {
        ++$this->depth;
    }

    public function leave(): void
    {
        if ($this->depth === 0) {
            throw new LogicException('The extension lifecycle lock nesting state is unbalanced.');
        }
        --$this->depth;
    }
}
