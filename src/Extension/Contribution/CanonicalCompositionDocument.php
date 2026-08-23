<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;
use JsonException;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\CanonicalJson;
use Kumwe\App\Extension\Domain\Internal\StudioProfile\CanonicalJsonRejected;
use stdClass;

/**
 * One canonical Studio contribution document, carried by a manifest schema 6 package.
 *
 * Schema 6 declares a contribution as the exact bytes of a canonical Studio document rather than an
 * App paraphrase: the manifest member holds the document's canonical JSON string, this class decodes
 * it with objects kept as objects, and refuses any string that is not byte-identical to the canonical
 * serialization of what it decodes to. That one rule is what makes every later comparison a byte
 * comparison — the signed manifest, provider registration and the stored artifact can only ever agree
 * or differ, never "normalize". Schema and profile validation happen where the document is admitted,
 * in `ManifestContributionSet::fromManifest()`, so admission and install repeat them identically.
 *
 * @since  2.0.0
 */
final readonly class CanonicalCompositionDocument implements ContributionDefinition
{
    /**
     * Largest canonical document one declaration may carry, matching the profile's schema budget.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_CANONICAL_BYTES = 262144;

    /**
     * The decoded document, objects as `stdClass` and arrays as lists.
     *
     * @var    stdClass
     * @since  2.0.0
     */
    public stdClass $document;

    /**
     * The document's identity under its kind's identity member.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $identity;

    /**
     * Accept one canonical document string for one contribution kind.
     *
     * @param   CanonicalCompositionKind  $kind       Which published schema the document claims.
     * @param   string                    $canonical  The document's canonical JSON bytes.
     *
     * @throws  InvalidArgumentException  When the string is over budget, not valid JSON, not an
     *          object, not in canonical form, or missing its identity member.
     *
     * @since   2.0.0
     */
    public function __construct(
        public CanonicalCompositionKind $kind,
        public string $canonical,
    ) {
        if (strlen($canonical) > self::MAXIMUM_CANONICAL_BYTES) {
            throw new InvalidArgumentException(sprintf(
                'A canonical composition document cannot exceed %d bytes.',
                self::MAXIMUM_CANONICAL_BYTES,
            ));
        }
        try {
            $decoded = json_decode($canonical, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'A canonical composition document must be valid JSON.',
                0,
                $exception,
            );
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('A canonical composition document must be a JSON object.');
        }
        try {
            $expected = CanonicalJson::stringify($decoded);
        } catch (CanonicalJsonRejected $rejection) {
            throw new InvalidArgumentException(
                'A canonical composition document must stay inside the canonical JSON form.',
                0,
                $rejection,
            );
        }
        if ($expected !== $canonical) {
            throw new InvalidArgumentException(
                'A composition document must be declared in its exact canonical serialization.',
            );
        }
        $identity = $decoded->{$kind->identityMember()} ?? null;
        if (!is_string($identity) || $identity === '') {
            throw new InvalidArgumentException(sprintf(
                'A %s document must carry its "%s" identity.',
                $kind->value,
                $kind->identityMember(),
            ));
        }
        $this->document = $decoded;
        $this->identity = $identity;
    }

    /**
     * The kind-scoped identity this document claims, matching its host binding's identifier.
     *
     * @return  string  The kind value and the document's `type` or `id` member joined by one space,
     *          so two kinds may reuse one identity without colliding in an index.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->kind->value . ' ' . $this->identity;
    }

    /**
     * The document's own identity member, without the kind scope.
     *
     * @return  string  The `type` or `id` member as declared.
     *
     * @since   2.0.0
     */
    public function identity(): string
    {
        return $this->identity;
    }

    /**
     * Export the comparable structure reconciliation uses.
     *
     * The canonical string is the whole document, so equality of two exports is byte-equivalence
     * of the documents themselves.
     *
     * @return  array<string, mixed>  Kind, identity and canonical bytes.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'id' => $this->identity,
            'canonical' => $this->canonical,
        ];
    }
}
