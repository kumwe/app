<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

/**
 * Closed vocabulary describing what authority an MCP tool exercises when it is called.
 *
 * A capability answers who may call a tool; this answers what calling it costs if the caller is wrong.
 * Every tool in `McpCapabilityCatalog` declares exactly one class, and `McpCatalogValidator` turns the
 * declaration into a rule rather than a note: the annotation hints, the capability requirement and the
 * operation identity a tool publishes are all checked against the class it claims, so a tool that
 * removes state while claiming to be an ordinary write fails the catalogue before a server is built.
 *
 * The classes are not a severity ladder and must not be read as one. Each names a different question an
 * operator has to answer, and a tool that raises more than one is classified by the first of these that
 * applies, in this order: does it change which third-party code runs (`Trust`); does it reach beyond the
 * caller's site (`InstallationGlobal`); does it change how an identity authenticates (`Credential`); does
 * it remove state (`Destructive`); does it write at all (`ScopedWrite`); otherwise `Read`. Trust leads
 * because admitting or withdrawing extension code changes what every later answer is worth, and
 * installation reach comes next because a mistake there cannot be contained by the site boundary.
 *
 * "Reach" is read as the authority a call exercises, not only the rows it writes. Approving a physical
 * schema plan writes one approval in the caller's own scope and is `InstallationGlobal` all the same,
 * because what it authorizes is data-definition work under an installation-wide fence that every site
 * waits behind. Publishing a business-definition version, by contrast, is `ScopedWrite`: definitions are
 * keyed by site, and publishing one confers no authority outside it.
 *
 * @since  2.0.0
 */
enum McpRiskClass: string
{
    /**
     * Returns state the caller is already authorized to see and changes nothing.
     *
     * @since  2.0.0
     */
    case Read = 'read';

    /**
     * Writes inside the caller's own site and leaves the written state recoverable through this surface.
     *
     * @since  2.0.0
     */
    case ScopedWrite = 'scoped_write';

    /**
     * Removes or supersedes state that this surface offers the caller no way to put back.
     *
     * @since  2.0.0
     */
    case Destructive = 'destructive';

    /**
     * Changes the material or the account state an identity authenticates with.
     *
     * @since  2.0.0
     */
    case Credential = 'credential';

    /**
     * Changes which third-party code this installation will admit, execute or keep executing.
     *
     * @since  2.0.0
     */
    case Trust = 'trust';

    /**
     * Takes effect across every site of the installation rather than only the caller's own.
     *
     * @since  2.0.0
     */
    case InstallationGlobal = 'installation_global';

    /**
     * Report whether a tool of this class changes stored state.
     *
     * @return  bool  False for `Read` alone; true for every writing class.
     *
     * @since   2.0.0
     */
    public function changesState(): bool
    {
        return $this !== self::Read;
    }

    /**
     * Report whether a tool of this class must name one capability in the catalogue.
     *
     * `Read` and `ScopedWrite` may leave it null, because a reading tool can be admitted by
     * authentication alone and an ordinary write may resolve the exact permission it needs from the
     * arguments it was given — a workflow transition authorizes the transition it was asked for. The
     * four elevated classes may not: an operator granting a token must be able to see, from the
     * catalogue alone, which grant admits a credential, trust, installation-wide or destructive call.
     *
     * @return  bool  True for `Destructive`, `Credential`, `Trust` and `InstallationGlobal`.
     *
     * @since   2.0.0
     */
    public function requiresDeclaredCapability(): bool
    {
        return match ($this) {
            self::Read, self::ScopedWrite => false,
            default => true,
        };
    }

    /**
     * Report whether a tool of this class must publish the destructive annotation hint.
     *
     * Only `Destructive` must, because that class is defined by the removal a client should confirm
     * before it happens. A credential, trust or installation-wide tool may or may not remove state —
     * registering a signing key does not — so the hint is permitted there rather than required.
     *
     * @return  bool  True for `Destructive` alone.
     *
     * @since   2.0.0
     */
    public function requiresDestructiveAnnotation(): bool
    {
        return $this === self::Destructive;
    }

    /**
     * Report whether a tool of this class may publish the destructive annotation hint at all.
     *
     * A tool annotated destructive while claiming `Read` or `ScopedWrite` has mis-declared one of the
     * two, which is the contradiction this predicate exists to fail.
     *
     * @return  bool  False for `Read` and `ScopedWrite`; true for the four elevated classes.
     *
     * @since   2.0.0
     */
    public function permitsDestructiveAnnotation(): bool
    {
        return match ($this) {
            self::Read, self::ScopedWrite => false,
            default => true,
        };
    }

    /**
     * Report whether the effect of a tool of this class can leave the caller's site.
     *
     * Used by the operator-facing documentation and by the catalogue validator, which refuses a tool
     * that claims a reach beyond the site while also claiming to be read-only.
     *
     * @return  bool  True for `Trust` and `InstallationGlobal`.
     *
     * @since   2.0.0
     */
    public function reachesBeyondTheCallersSite(): bool
    {
        return $this === self::Trust || $this === self::InstallationGlobal;
    }

    /**
     * Return the one-line operator-facing meaning of this class.
     *
     * Written for the person deciding what a token may hold, so it names the consequence rather than
     * the mechanism.
     *
     * @return  non-empty-string  Sentence describing what a tool of this class does when it succeeds.
     *
     * @since   2.0.0
     */
    public function summary(): string
    {
        return match ($this) {
            self::Read => 'Reads state the caller is already authorized to see and changes nothing.',
            self::ScopedWrite => 'Writes within the caller\'s site, recoverably through this surface.',
            self::Destructive => 'Removes or supersedes state this surface cannot put back.',
            self::Credential => 'Changes the material or account state an identity authenticates with.',
            self::Trust => 'Changes which third-party code this installation admits or keeps executing.',
            self::InstallationGlobal => 'Takes effect across every site of the installation.',
        };
    }
}
