<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Concurrency;

use InvalidArgumentException;

final readonly class IfMatch
{
    /** @var list<EntityTag> */
    private array $tags;

    /** @param list<EntityTag> $tags */
    private function __construct(
        private bool $wildcard,
        array $tags,
    ) {
        $this->tags = $tags;
    }

    public static function fromHeader(string $header): self
    {
        $header = trim($header);

        if ($header === '*') {
            return new self(true, []);
        }

        if ($header === '') {
            throw new InvalidArgumentException('If-Match cannot be empty.');
        }

        $tags = [];

        $values = preg_split('/,(?=(?:[^\"]*\"[^\"]*\")*[^\"]*$)/', $header);

        if (!is_array($values)) {
            throw new InvalidArgumentException('If-Match could not be parsed.');
        }

        foreach ($values as $value) {
            $tag = EntityTag::fromHeader($value);

            if ($tag->isWeak()) {
                throw new InvalidArgumentException('If-Match requires strong entity tags.');
            }

            $tags[] = $tag;
        }

        return new self(false, $tags);
    }

    public function matches(EntityTag $current, bool $resourceExists = true): bool
    {
        if (!$resourceExists) {
            return false;
        }

        if ($this->wildcard) {
            return true;
        }

        foreach ($this->tags as $candidate) {
            if ($candidate->stronglyEquals($current)) {
                return true;
            }
        }

        return false;
    }

    public function isWildcard(): bool
    {
        return $this->wildcard;
    }
}
