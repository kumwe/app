<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Concurrency;

use InvalidArgumentException;
use Stringable;

final readonly class EntityTag implements Stringable
{
    private function __construct(
        private string $opaqueValue,
        private bool $weak,
    ) {
    }

    public static function fromVersion(int $version): self
    {
        if ($version < 0) {
            throw new InvalidArgumentException('An entity version cannot be negative.');
        }

        return new self('v' . $version, false);
    }

    public static function fromHeader(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^(W\/)?"([\x21\x23-\x7E\x80-\xFF]*)"$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('The entity tag is not valid RFC 9110 syntax.');
        }

        return new self($matches[2], ($matches[1] ?? '') === 'W/');
    }

    public function isWeak(): bool
    {
        return $this->weak;
    }

    public function stronglyEquals(self $other): bool
    {
        return !$this->weak && !$other->weak && hash_equals($this->opaqueValue, $other->opaqueValue);
    }

    public function __toString(): string
    {
        return ($this->weak ? 'W/' : '') . '"' . $this->opaqueValue . '"';
    }
}
