<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Automation;

use InvalidArgumentException;

final readonly class IdempotencyResult
{
    /** @var array<string, mixed> */
    private array $body;
    private string $bodyDigest;

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(private int $statusCode, array $body)
    {
        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('An idempotent result requires a valid HTTP status code.');
        }

        $this->body = $body;
        $this->bodyDigest = CanonicalJson::digest($body);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return $this->body;
    }

    public function bodyDigest(): string
    {
        return $this->bodyDigest;
    }
}
