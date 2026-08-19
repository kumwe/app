<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

/**
 * Decides who may read the exposition endpoint, and refuses by default.
 *
 * An exposition endpoint is an unauthenticated inventory of a system's internal state: queue depth,
 * failure counts, release identifier, whether the process is healthy. None of it is personal data and
 * none of it is a credential, but all of it is reconnaissance, and `config/observability.php` already
 * declared `public => false`. This class makes that declaration mean something at runtime.
 *
 * The protection is a shared bearer token compared in constant time, and the reasoning for choosing it
 * over the alternatives is worth stating. Network-level isolation alone was rejected as the *only*
 * control: it is invisible to the application, so nothing in the repository can prove it is in place,
 * and a container that gets exposed by a misconfigured ingress fails open with no second line. Reusing
 * the platform's own access tokens was rejected because it would put a database query and a capability
 * check on the scrape path — the one path that has to keep answering while the database is the thing
 * going wrong. A static token needs no I/O, no state and no clock, so the endpoint stays answerable in
 * exactly the conditions an operator most needs it.
 *
 * Three deliberate properties follow. The endpoint is off unless a deployment turns it on. When it is
 * on and not public, a missing token means the endpoint stays invisible — it answers 404, not 401, so
 * a misconfigured deployment does not advertise that a metrics surface exists. And the token is never
 * logged, never echoed and never compared with `===`.
 *
 * @since  2.0.0
 */
final readonly class MetricsAccessPolicy
{
    /**
     * The scrape is refused because no metrics surface is exposed at all.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ABSENT = 'absent';

    /**
     * The scrape is refused because it presented no credential, or the wrong one.
     *
     * @var    string
     * @since  2.0.0
     */
    public const UNAUTHORIZED = 'unauthorized';

    /**
     * The scrape may read the exposition.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ALLOWED = 'allowed';

    /**
     * Bind the policy to the deployment's decision.
     *
     * @param  bool     $enabled  Whether any metrics surface is exposed by this deployment.
     * @param  bool     $public   Whether the surface may be scraped with no credential at all.
     * @param  ?string  $token    Shared bearer token a non-public surface requires, or null when none
     *         is configured — in which case the surface stays invisible.
     *
     * @since  2.0.0
     */
    public function __construct(
        private bool $enabled,
        private bool $public,
        private ?string $token,
    ) {
    }

    /**
     * Decide one scrape.
     *
     * @param   string  $authorization  Raw `Authorization` header offered by the scraper.
     *
     * @return  string  One of `absent`, `unauthorized` or `allowed`.
     *
     * @since   2.0.0
     */
    public function decide(string $authorization): string
    {
        if (!$this->enabled) {
            return self::ABSENT;
        }
        if ($this->public) {
            return self::ALLOWED;
        }
        if ($this->token === null || $this->token === '') {
            return self::ABSENT;
        }
        $offered = preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches) === 1 ? $matches[1] : '';

        return $offered !== '' && hash_equals($this->token, $offered) ? self::ALLOWED : self::UNAUTHORIZED;
    }
}
