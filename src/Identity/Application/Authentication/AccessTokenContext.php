<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authentication;

use InvalidArgumentException;

final readonly class AccessTokenContext
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'kumwe-http' => ['api'],
        'kumwe-cli' => ['management'],
        'kumwe-mcp' => ['mcp'],
    ];

    private function __construct(public string $audience, public string $purpose)
    {
    }

    public static function fromStrings(string $audience, string $purpose): self
    {
        $audience = strtolower(trim($audience));
        $purpose = strtolower(trim($purpose));
        if (!in_array($purpose, self::ALLOWED[$audience] ?? [], true)) {
            throw new InvalidArgumentException('The access-token audience and purpose combination is not supported.');
        }
        return new self($audience, $purpose);
    }

    public static function http(): self
    {
        return new self('kumwe-http', 'api');
    }

    public static function cli(): self
    {
        return new self('kumwe-cli', 'management');
    }

    public static function mcp(): self
    {
        return new self('kumwe-mcp', 'mcp');
    }
}
