<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain\Internal\StudioProfile;

use LogicException;
use Kumwe\App\Studio\Domain\Contract\SchemaPropertyValidator;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;

/**
 * Backward-compatible contribution-only view over the neutral Studio contract registry.
 *
 * The former internal type deliberately keeps its six-kind admission boundary and refusal text.
 * New host integrations use {@see StudioContractSchemas} directly for the wider runtime document set.
 *
 * @since  2.0.0
 */
final readonly class StudioContributionSchemas
{
    /**
     * Contribution documents manifest schema 6 admits.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array CONTRIBUTION_KINDS = StudioContractSchemas::CONTRIBUTION_KINDS;

    /**
     * Restrict one neutral registry to the legacy contribution surface.
     *
     * @param  StudioContractSchemas  $schemas  Neutral registry over the exact vendored corpus.
     *
     * @since  2.0.0
     */
    private function __construct(private StudioContractSchemas $schemas)
    {
    }

    /**
     * Load the neutral registry while preserving the old shared-default construction behavior.
     *
     * @param   ?string  $schemaDirectory  Corpus schema directory, or null for the vendored default.
     *
     * @return  self  Contribution-only adapter.
     *
     * @since   2.0.0
     */
    public static function fromVendoredCorpus(?string $schemaDirectory = null): self
    {
        /** @var self|null $shared */
        static $shared = null;
        if ($schemaDirectory === null) {
            return $shared ??= new self(StudioContractSchemas::fromContributionCorpus());
        }

        return new self(StudioContractSchemas::fromContributionCorpus($schemaDirectory));
    }

    /**
     * Return a validator only for a canonical extension contribution kind.
     *
     * @param   string  $kind  Candidate contribution kind.
     *
     * @return  SchemaPropertyValidator  Validator over the exact pinned schema.
     *
     * @throws  LogicException  When the kind belongs outside the legacy contribution surface.
     *
     * @since   2.0.0
     */
    public function validator(string $kind): SchemaPropertyValidator
    {
        if (!in_array($kind, self::CONTRIBUTION_KINDS, true)) {
            throw new LogicException(sprintf('"%s" is not a canonical Studio contribution kind.', $kind));
        }

        return $this->schemas->validator($kind);
    }
}
