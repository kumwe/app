<?php

declare(strict_types=1);

namespace Kumwe\CMS\Content\Domain;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class PublicationWindow
{
    public function __construct(
        private ?DateTimeImmutable $startsAt = null,
        private ?DateTimeImmutable $endsAt = null,
    ) {
        if ($startsAt !== null && $endsAt !== null && $startsAt >= $endsAt) {
            throw new InvalidArgumentException('A publication window must end after it starts.');
        }
    }

    public static function unbounded(): self
    {
        return new self();
    }

    public function startsAt(): ?DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function endsAt(): ?DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function contains(DateTimeImmutable $instant): bool
    {
        if ($this->startsAt !== null && $instant < $this->startsAt) {
            return false;
        }

        return $this->endsAt === null || $instant < $this->endsAt;
    }
}
