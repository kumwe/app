<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\Delivery\Console\Command\ConsoleAuthorizer;
use Kumwe\App\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use RuntimeException;

/**
 * Real console authorizer over a unit token verifier, plus the owner-only token file the console requires.
 *
 * Console command unit tests authenticate exactly as the dispatcher does: `--site` and `--token-file` name a
 * protected file whose token the verifier accepts only for the `kumwe-cli` audience and `management` purpose,
 * and the resulting principal holds the capabilities the case grants. Call `cleanup()` from `tearDown()`.
 *
 * @since  2.0.0
 */
final class ConsoleCommandFixture
{
    /**
     * Plaintext unit token the verifier accepts.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string TOKEN = 'unit-console-token';

    /**
     * Owner-only token file, once written.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $tokenFile = null;

    /**
     * Build the real console authorizer for a principal holding the named capabilities.
     *
     * @param   list<string>  $capabilities  Capabilities the verified principal holds.
     *
     * @return  ConsoleAuthorizer  Authorizer under test.
     *
     * @since   2.0.0
     */
    public function authorizer(array $capabilities): ConsoleAuthorizer
    {
        return new ConsoleAuthorizer(new class ($capabilities) implements AccessTokenVerifier {
            /**
             * Keep the capabilities the unit principal holds.
             *
             * @param  list<string>  $capabilities  Granted capabilities.
             *
             * @since  2.0.0
             */
            public function __construct(private readonly array $capabilities)
            {
            }

            /**
             * Authenticate the unit token for the console audience and management purpose only.
             *
             * @param   string  $token           Presented bearer credential.
             * @param   string  $audience        Surface the token must belong to.
             * @param   string  $purpose         Purpose the token must carry.
             * @param   string  $siteIdentifier  Site the token must be scoped to.
             *
             * @return  ?AuthenticatedPrincipal  The unit principal, or null off the happy path.
             *
             * @since   2.0.0
             */
            public function verify(
                string $token,
                string $audience = 'kumwe-http',
                string $purpose = 'api',
                string $siteIdentifier = 'default',
            ): ?AuthenticatedPrincipal {
                if (
                    $token !== ConsoleCommandFixture::TOKEN
                    || $audience !== 'kumwe-cli'
                    || $purpose !== 'management'
                ) {
                    return null;
                }

                return AuthorizationContext::principal($this->capabilities);
            }
        });
    }

    /**
     * Answer the shared `--site` and `--token-file` options, writing the token file on first use.
     *
     * @return  list<string>  Authorization options.
     *
     * @throws  RuntimeException  When the file cannot be written.
     *
     * @since   2.0.0
     */
    public function options(): array
    {
        if ($this->tokenFile === null) {
            $path = tempnam(sys_get_temp_dir(), 'kumwe-unit-console-');
            if (!is_string($path) || file_put_contents($path, self::TOKEN) === false || !chmod($path, 0o600)) {
                throw new RuntimeException('The unit console token file could not be written.');
            }
            $this->tokenFile = $path;
        }

        return ['--site=default', '--token-file=' . $this->tokenFile];
    }

    /**
     * Remove the token file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function cleanup(): void
    {
        if ($this->tokenFile !== null && is_file($this->tokenFile)) {
            unlink($this->tokenFile);
        }
        $this->tokenFile = null;
    }
}
