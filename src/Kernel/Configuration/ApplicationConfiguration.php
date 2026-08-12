<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Http\Security\TrustedProxyMatcher;

/**
 * The complete, already-validated settings one Kumwe process runs on.
 *
 * `ConfigurationFactory` builds a single instance during boot and `ContainerFactory` shares it, so
 * no other class needs to consult the environment: a collaborator that wants a setting asks for this
 * object. The constructor is the deployment's coherence check — an absolute base URL that is HTTPS
 * in production, a resolvable public site context, a parseable proxy trust boundary, secrets that
 * are long enough and independent of one another, well-formed deployment identities, and an
 * administrator session inside the supported window — so a misconfigured deployment refuses to start
 * instead of failing unpredictably under traffic.
 *
 * @since  2.0.0
 */
final readonly class ApplicationConfiguration
{
    /**
     * Capture and validate the settings the whole process will read.
     *
     * @param   RuntimeEnvironment      $environment                   Deployment mode, which decides how
     *          strict the remaining checks are.
     * @param   bool                    $debug                         Whether verbose diagnostics and debug-level
     *          logging are enabled.
     * @param   string                  $baseUrl                       Absolute URL the site is served from; it
     *          must use HTTPS in production.
     * @param   string                  $publicSite                    Site context anonymous front-end requests
     *          resolve to.
     * @param   string                  $siteContentProfile            Built-in content dataset selected for a
     *          newly installed site; the demo catalog discovers the shipped vocabulary at reconciliation.
     * @param   string                  $businessProfile               Named business demonstration dataset
     *          installed and maintained alongside the selected site content, or `none` for no business data.
     * @param   non-empty-list<string>  $trustedHosts                  Host names the application answers to; a
     *          request for any other host is refused.
     * @param   list<string>            $trustedProxies                Addresses or CIDR ranges whose forwarding
     *          headers may be believed when rebuilding the client address.
     * @param   int                     $maxBodyBytes                  Largest request body accepted before the
     *          request is rejected.
     * @param   int                     $administratorSessionSeconds   Administrator session lifetime, from 300
     *          to 604800 seconds.
     * @param   bool                    $allowUnsignedLocalExtensions  Whether an extension installed from a local
     *          path may skip signature verification; meant for development, not production.
     * @param   string                  $release                       Version stamp recorded on queued jobs and
     *          reported to business schema execution, tying stored work to the build that made it.
     * @param   string                  $secret                        Application signing secret of at least 32
     *          bytes, used for session and token material.
     * @param   string                  $runtimeSigningKeyId           Identifier of the key currently signing
     *          the compiled extension runtime map.
     * @param   string                  $runtimeSigningKey             Active runtime signing key, at least 32
     *          bytes and deliberately independent of `$secret`, so compromising one cannot forge
     *          the other.
     * @param   array<string, string>   $runtimePreviousSigningKeys    Retired signing keys by identifier,
     *          kept so already-published runtime maps still verify while a rotation completes.
     * @param   string                  $deploymentId                  Stable name of the deployment this process
     *          belongs to.
     * @param   string                  $replicaId                     Stable name of the replica, distinguishing
     *          peers within one deployment.
     * @param   string                  $processId                     Stable name of the process within the replica.
     * @param   string                  $instanceId                    Stable name of this instance; the four
     *          identities together derive the lease a runtime map compilation holds.
     * @param   DatabaseConfiguration   $database                      Connection settings for the relational store.
     * @param   RedisConfiguration      $redis                         Connection settings for the Redis server.
     *
     * @throws  InvalidArgumentException  When a setting is malformed, a secret is too short or
     *          reused, an identity is not a stable identifier, or a production-only rule is violated.
     *
     * @since   2.0.0
     */
    public function __construct(
        public RuntimeEnvironment $environment,
        public bool $debug,
        public string $baseUrl,
        public string $publicSite,
        public string $siteContentProfile,
        public string $businessProfile,
        public array $trustedHosts,
        public array $trustedProxies,
        public int $maxBodyBytes,
        public int $administratorSessionSeconds,
        public bool $allowUnsignedLocalExtensions,
        public string $release,
        public string $secret,
        public string $runtimeSigningKeyId,
        public string $runtimeSigningKey,
        public array $runtimePreviousSigningKeys,
        public string $deploymentId,
        public string $replicaId,
        public string $processId,
        public string $instanceId,
        public DatabaseConfiguration $database,
        public RedisConfiguration $redis,
    ) {
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('APP_BASE_URL must contain an absolute URL.');
        }

        if ($environment === RuntimeEnvironment::Production && !str_starts_with($baseUrl, 'https://')) {
            throw new InvalidArgumentException('Production APP_BASE_URL must use HTTPS.');
        }
        SiteContext::fromString($publicSite);
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/D', $siteContentProfile) !== 1) {
            throw new InvalidArgumentException(
                'KUMWE_SITE_CONTENT_PROFILE must be a lowercase profile name such as documentation or blank.',
            );
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/D', $businessProfile) !== 1) {
            throw new InvalidArgumentException(
                'KUMWE_BUSINESS_PROFILE must be a lowercase profile name such as vdm, or none.',
            );
        }

        if ($trustedHosts === []) {
            throw new InvalidArgumentException('At least one trusted host is required.');
        }

        // Fail during bootstrap rather than silently running with a malformed trust boundary.
        new TrustedProxyMatcher($trustedProxies);

        if (strlen($secret) < 32) {
            throw new InvalidArgumentException('APP_SECRET must contain at least 32 bytes.');
        }
        if (strlen($runtimeSigningKey) < 32) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_SIGNING_KEY must contain at least 32 bytes.');
        }
        if (hash_equals($secret, $runtimeSigningKey)) {
            throw new InvalidArgumentException('The runtime publication key must be independent from APP_SECRET.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $runtimeSigningKeyId) !== 1) {
            throw new InvalidArgumentException('EXTENSION_RUNTIME_SIGNING_KEY_ID is invalid.');
        }
        foreach ($runtimePreviousSigningKeys as $keyId => $key) {
            if (
                !is_string($keyId)
                || preg_match('/^[a-z0-9][a-z0-9._:-]{2,126}$/D', $keyId) !== 1
                || !is_string($key)
                || strlen($key) < 32
                || hash_equals($runtimeSigningKeyId, $keyId)
            ) {
                throw new InvalidArgumentException('EXTENSION_RUNTIME_PREVIOUS_KEYS is invalid.');
            }
        }
        foreach ([$deploymentId, $replicaId, $processId, $instanceId] as $identity) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{1,126}[A-Za-z0-9]$/D', $identity) !== 1) {
                throw new InvalidArgumentException('Runtime deployment identities are invalid.');
            }
        }

        if ($administratorSessionSeconds < 300 || $administratorSessionSeconds > 604_800) {
            throw new InvalidArgumentException(
                'APP_ADMIN_SESSION_SECONDS must be between 300 and 604800 seconds.',
            );
        }
    }

    /**
     * Report whether this process runs under production rules.
     *
     * Callers use it to switch on the caching that is only safe when the deployment is immutable,
     * such as the compiled route table and the template cache.
     *
     * @return  bool  True when `APP_ENV` selected `RuntimeEnvironment::Production`.
     *
     * @since   2.0.0
     */
    public function isProduction(): bool
    {
        return $this->environment === RuntimeEnvironment::Production;
    }
}
