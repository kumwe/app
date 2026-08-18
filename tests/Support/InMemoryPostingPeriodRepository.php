<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use DateTimeImmutable;
use Kumwe\CMS\BusinessRecord\Application\PostingPeriodRepository;
use Kumwe\CMS\BusinessRecord\Domain\PostingPeriod;

/**
 * Serves posting-period declarations from memory for focused unit tests.
 *
 * The store keys rows exactly the way the table's identity index does — site, organization marker,
 * key — so the administrative service's find-then-save flow behaves as it does over the real adapter,
 * while the containment read applies the same closed-and-contains rule without scope-precedence
 * subtleties, which are the Doctrine adapter's own to prove.
 *
 * @since  2.0.0
 */
final class InMemoryPostingPeriodRepository implements PostingPeriodRepository
{
    /**
     * Declarations keyed by site, organization marker and key.
     *
     * @var    array<string, PostingPeriod>
     * @since  2.0.0
     */
    private array $periods = [];

    /**
     * Read one declaration by its scope and stable key.
     *
     * @param   string   $siteIdentifier          Site the declaration belongs to.
     * @param   ?string  $organizationIdentifier  Organization scope, or null for a site-wide one.
     * @param   string   $key                     Stable key the declaration was made under.
     *
     * @return  ?PostingPeriod  The declaration, or null when the scope holds none under that key.
     *
     * @since   2.0.0
     */
    public function find(string $siteIdentifier, ?string $organizationIdentifier, string $key): ?PostingPeriod
    {
        return $this->periods[self::identity($siteIdentifier, $organizationIdentifier, $key)] ?? null;
    }

    /**
     * Store one declaration under its identity, replacing any previous state.
     *
     * @param   PostingPeriod  $period  Declaration to keep.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function save(PostingPeriod $period): void
    {
        $this->periods[self::identity(
            $period->siteIdentifier,
            $period->organizationIdentifier,
            $period->key,
        )] = $period;
    }

    /**
     * List the declarations governing one site, in insertion order.
     *
     * @param   string   $siteIdentifier          Site whose declarations are listed.
     * @param   ?string  $organizationIdentifier  Organization filter beside the site-wide rows, or
     *          null for every declaration on the site.
     *
     * @return  list<PostingPeriod>  Matching declarations.
     *
     * @since   2.0.0
     */
    public function listFor(string $siteIdentifier, ?string $organizationIdentifier): array
    {
        return array_values(array_filter(
            $this->periods,
            static fn (PostingPeriod $period): bool => $period->siteIdentifier === $siteIdentifier
                && ($organizationIdentifier === null
                    || $period->organizationIdentifier === null
                    || $period->organizationIdentifier === $organizationIdentifier),
        ));
    }

    /**
     * Answer the first closed declaration containing the instant.
     *
     * @param   string             $siteIdentifier          Site whose declarations are consulted.
     * @param   ?string            $organizationIdentifier  Organization consulted beside the
     *          site-wide declarations, or null for site-wide only.
     * @param   DateTimeImmutable  $instant                 Posting instant to classify.
     *
     * @return  ?PostingPeriod  The refusing declaration, or null when none contains the instant.
     *
     * @since   2.0.0
     */
    public function closedPeriodContaining(
        string $siteIdentifier,
        ?string $organizationIdentifier,
        DateTimeImmutable $instant,
    ): ?PostingPeriod {
        foreach ($this->periods as $period) {
            if (
                $period->siteIdentifier === $siteIdentifier
                && ($period->organizationIdentifier === null
                    || $period->organizationIdentifier === $organizationIdentifier)
                && $period->isClosed()
                && $period->contains($instant)
            ) {
                return $period;
            }
        }

        return null;
    }

    /**
     * Compile the identity key the store files a declaration under.
     *
     * @param   string   $siteIdentifier          Site half of the identity.
     * @param   ?string  $organizationIdentifier  Organization half, empty for site-wide.
     * @param   string   $key                     Declared stable key.
     *
     * @return  string  Composite identity.
     *
     * @since   2.0.0
     */
    private static function identity(string $siteIdentifier, ?string $organizationIdentifier, string $key): string
    {
        return $siteIdentifier . ':' . ($organizationIdentifier ?? '') . ':' . $key;
    }
}
