<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\BusinessRecord\Domain\RecordValueGuard;

final readonly class RecordFingerprint
{
    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('The business-record fingerprint key requires at least 32 bytes.');
        }
    }

    public function digest(mixed $value): string
    {
        try {
            $json = json_encode(
                $this->canonical($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('A business-record request cannot be fingerprinted.', 0, $exception);
        }

        return hash_hmac('sha256', $json, $this->key);
    }

    private function canonical(mixed $value): mixed
    {
        $value = RecordValueGuard::canonical($value);
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonical($item);
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
        }

        return $value;
    }
}
