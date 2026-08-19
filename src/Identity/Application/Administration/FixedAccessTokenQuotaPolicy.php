<?php

declare(strict_types=1);

namespace Kumwe\App\Identity\Application\Administration;

use InvalidArgumentException;

/**
 * The shipped `AccessTokenQuotaPolicy`: one ceiling applied to every subject and scope alike.
 *
 * `ContainerFactory` binds this with its default of 25, which is the limit an untouched installation
 * runs under. It consults none of the scope arguments, so a deployment that wants a different number
 * changes the constructor argument and one that wants the number to vary by purpose or audience replaces
 * this class rather than configuring it. The constructor bounds the ceiling itself, so a misconfigured
 * container fails at wiring time instead of admitting an unbounded number of live tokens.
 *
 * @since  2.0.0
 */
final readonly class FixedAccessTokenQuotaPolicy implements AccessTokenQuotaPolicy
{
    /**
     * Fix the number of live tokens any one subject and scope may hold.
     *
     * @param   int  $maximumActiveTokens  Live tokens permitted per subject, site, audience and purpose.
     *
     * @throws  InvalidArgumentException  When the ceiling falls outside the accepted range of 1 to 1,000.
     *
     * @since   2.0.0
     */
    public function __construct(private int $maximumActiveTokens = 25)
    {
        if ($maximumActiveTokens < 1 || $maximumActiveTokens > 1_000) {
            throw new InvalidArgumentException('The active API-token quota must be between 1 and 1,000.');
        }
    }

    /**
     * Admit the token only while the counted scope sits below the fixed ceiling.
     *
     * The four scope arguments are accepted to satisfy the port but are not read: the same number governs
     * every subject, site, audience and purpose. Refusal is raised rather than returned, which aborts the
     * issuing transaction the caller made the count inside.
     *
     * @param   string  $subjectId       UUID of the user the new token would authenticate as.
     * @param   string  $siteIdentifier  Site the new token would be confined to.
     * @param   string  $audience        Audience the new token would be accepted for.
     * @param   string  $purpose         Purpose the new token would be issued under.
     * @param   int     $activeTokens    Live tokens the caller already counted for exactly this scope.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the counted scope has already reached the fixed ceiling.
     *
     * @since   2.0.0
     */
    public function assertAllowed(
        string $subjectId,
        string $siteIdentifier,
        string $audience,
        string $purpose,
        int $activeTokens,
    ): void {
        if ($activeTokens >= $this->maximumActiveTokens) {
            throw new InvalidArgumentException('The active token quota for this subject and scope has been reached.');
        }
    }
}
