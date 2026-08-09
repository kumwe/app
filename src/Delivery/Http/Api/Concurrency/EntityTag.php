<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Concurrency;

use InvalidArgumentException;
use Stringable;

/**
 * An RFC 9110 entity tag, parsed from or rendered into `ETag` and `If-Match` header syntax.
 *
 * Every tag the API emits is derived from an entity's integer version counter, so `"v7"` on the wire
 * always means "revision 7 of this resource" and optimistic concurrency reduces to comparing two
 * versions. Holding the quoting, the weakness marker and the comparison rule in one value object is
 * what lets a responder emit an `ETag` and `IfMatch` test a precondition without either of them
 * re-deriving the header grammar.
 *
 * @since  2.0.0
 */
final readonly class EntityTag implements Stringable
{
    /**
     * Hold an already-validated tag; obtain one through `fromVersion()` or `fromHeader()`.
     *
     * @param  string  $opaqueValue  Tag characters without the surrounding quotes or `W/` marker.
     * @param  bool    $weak         Whether the tag was received with the `W/` weakness marker.
     *
     * @since  2.0.0
     */
    private function __construct(
        private string $opaqueValue,
        private bool $weak,
    ) {
    }

    /**
     * Build the strong tag that stands for one revision of a versioned resource.
     *
     * This is the only tag shape Kumwe issues, so two tags name the same revision exactly when their
     * version numbers agree.
     *
     * @param   int  $version  Version counter of the stored entity; zero and above.
     *
     * @return  self  A strong tag whose opaque value is `v` followed by the version number.
     *
     * @throws  InvalidArgumentException  When the version is negative.
     *
     * @since   2.0.0
     */
    public static function fromVersion(int $version): self
    {
        if ($version < 0) {
            throw new InvalidArgumentException('An entity version cannot be negative.');
        }

        return new self('v' . $version, false);
    }

    /**
     * Parse a single entity tag from a header value, remembering whether the sender marked it weak.
     *
     * Surrounding whitespace is tolerated. Anything else outside the RFC 9110 grammar — missing
     * quotes, or a character the grammar excludes — is rejected rather than repaired, so a malformed
     * precondition can never be silently reinterpreted as a different tag.
     *
     * @param   string  $value  One entity tag as it appears in an `ETag` or `If-Match` header.
     *
     * @return  self  The parsed tag, weak when the value carried the `W/` marker.
     *
     * @throws  InvalidArgumentException  When the value is not valid entity-tag syntax.
     *
     * @since   2.0.0
     */
    public static function fromHeader(string $value): self
    {
        $value = trim($value);

        if (preg_match('/^(W\/)?"([\x21\x23-\x7E\x80-\xFF]*)"$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('The entity tag is not valid RFC 9110 syntax.');
        }

        return new self($matches[2], $matches[1] === 'W/');
    }

    /**
     * Report whether the tag is weak, which bars it from strong comparison.
     *
     * @return  bool  True when the tag was received with the `W/` marker.
     *
     * @since   2.0.0
     */
    public function isWeak(): bool
    {
        return $this->weak;
    }

    /**
     * Apply RFC 9110 strong comparison, which only two strong tags can pass.
     *
     * The opaque values are compared with `hash_equals()`, so the check does not reveal through its
     * timing where two tags first differ.
     *
     * @param   self  $other  Tag to compare against, usually the one a client sent as a precondition.
     *
     * @return  bool  True only when neither tag is weak and the opaque values are identical.
     *
     * @since   2.0.0
     */
    public function stronglyEquals(self $other): bool
    {
        return !$this->weak && !$other->weak && hash_equals($this->opaqueValue, $other->opaqueValue);
    }

    /**
     * Render the tag exactly as an `ETag` or `If-Match` header field value.
     *
     * @return  string  The quoted opaque value, prefixed with `W/` when the tag is weak.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return ($this->weak ? 'W/' : '') . '"' . $this->opaqueValue . '"';
    }
}
