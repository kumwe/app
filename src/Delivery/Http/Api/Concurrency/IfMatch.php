<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Concurrency;

use InvalidArgumentException;

/**
 * A parsed `If-Match` precondition, ready to be tested against a resource's current entity tag.
 *
 * Mutating API routes require this header so a lost update is refused rather than silently applied:
 * the client states which revision it read, and the handler compares that against the revision it is
 * about to overwrite. Only strong tags and the `*` wildcard survive parsing, because a weak tag
 * asserts that two representations are equivalent, not that they are the same revision.
 *
 * @since  2.0.0
 */
final readonly class IfMatch
{
    /**
     * Tags the client will accept, in header order; empty when the precondition is the wildcard.
     *
     * @var    list<EntityTag>
     * @since  2.0.0
     */
    private array $tags;

    /**
     * Hold an already-parsed precondition; obtain one through `fromHeader()`.
     *
     * @param  bool             $wildcard  Whether the header was `*`, matching any existing revision.
     * @param  list<EntityTag>  $tags      Strong tags the client will accept; empty for the wildcard.
     *
     * @since  2.0.0
     */
    private function __construct(
        private bool $wildcard,
        array $tags,
    ) {
        $this->tags = $tags;
    }

    /**
     * Parse an `If-Match` header line into a precondition.
     *
     * The list is split only on commas that fall outside quoted tag values, so a comma inside an
     * opaque value does not tear an entry in half. A weak tag is refused outright: `If-Match` is
     * defined to use strong comparison, and honouring a weak tag would let a merely equivalent
     * revision pass the check.
     *
     * @param   string  $header  Raw `If-Match` field value: either `*` or a comma-separated tag list.
     *
     * @return  self  The parsed precondition.
     *
     * @throws  InvalidArgumentException  When the header is empty, cannot be split, or holds a weak or
     *          malformed entity tag.
     *
     * @since   2.0.0
     */
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

    /**
     * Decide whether this precondition allows the mutation to go ahead.
     *
     * The wildcard matches any resource that exists, which is how a client says "replace whatever is
     * there, but do not create it". Passing `false` for `$resourceExists` therefore fails even the
     * wildcard, so no precondition can turn a missing resource into a successful write.
     *
     * @param   EntityTag  $current         Tag of the revision the caller is about to overwrite.
     * @param   bool       $resourceExists  Whether the target resource exists at all.
     *
     * @return  bool  True when the mutation may proceed; false is the caller's cue to answer 412.
     *
     * @since   2.0.0
     */
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

    /**
     * Report whether the precondition was the `*` wildcard rather than a list of tags.
     *
     * @return  bool  True when any existing revision satisfies the precondition.
     *
     * @since   2.0.0
     */
    public function isWildcard(): bool
    {
        return $this->wildcard;
    }
}
