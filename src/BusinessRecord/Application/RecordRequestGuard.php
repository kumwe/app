<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;
use Ramsey\Uuid\Uuid;

final class RecordRequestGuard
{
    public static function definition(string $identifier): void
    {
        if (
            !Uuid::isValid($identifier)
            && preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $identifier) !== 1
        ) {
            throw new InvalidArgumentException('A business-definition identifier is invalid.');
        }
    }

    public static function record(string $recordId): void
    {
        if ($recordId === '' || strlen($recordId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $recordId) === 1) {
            throw new InvalidArgumentException('A business-record ID must be a bounded identity without controls.');
        }
    }

    public static function expectedVersion(int $version): void
    {
        if ($version < 1) {
            throw new InvalidArgumentException('A business-record expected version must be positive.');
        }
    }

    public static function organization(?string $organization): void
    {
        if (
            $organization !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $organization) !== 1
        ) {
            throw new InvalidArgumentException('A business-record organization identifier is invalid.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function values(array $values, bool $allowEmpty = false): void
    {
        if ((!$allowEmpty && $values === []) || count($values) > 256) {
            throw new InvalidArgumentException('A business-record value set is empty or unbounded.');
        }
        foreach ($values as $handle => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('A business-record value set contains an invalid field handle.');
            }
            RecordValueGuard::assertValue($value);
        }
    }

    public static function handle(string $handle, string $kind): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
            throw new InvalidArgumentException(sprintf('A business-record %s handle is invalid.', $kind));
        }
    }

    private function __construct()
    {
    }
}
