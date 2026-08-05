<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\Authentication;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Identity\Domain\GrantScope;

final readonly class AuthenticatedPrincipal
{
    public const REQUEST_ATTRIBUTE = self::class;

    /** @var array<string, Capability> */
    private array $capabilities;

    /** @var list<PrincipalGrant> */
    private array $grants;

    /** @param array<mixed> $grants */
    private function __construct(
        private object $provenance,
        private string $subject,
        array $grants,
        private string $credentialId,
        private int $securityEpoch,
    ) {
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}'
            . '-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD';

        if (preg_match($uuidPattern, $subject) !== 1) {
            throw new InvalidArgumentException('An authenticated principal subject must be a canonical UUID.');
        }

        if (!array_is_list($grants)) {
            throw new InvalidArgumentException('Principal grants must be a list.');
        }

        if (
            $credentialId === ''
            || strlen($credentialId) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $credentialId) === 1
        ) {
            throw new InvalidArgumentException('A principal credential identity is invalid.');
        }

        if ($securityEpoch < 1) {
            throw new InvalidArgumentException('A principal security epoch must be positive.');
        }

        $indexed = [];
        $normalized = [];

        foreach ($grants as $grant) {
            if ($grant instanceof Capability) {
                $grant = new PrincipalGrant($grant, GrantScope::global());
            }

            if (!($grant instanceof PrincipalGrant)) {
                throw new InvalidArgumentException('Principal grants must be Capability or PrincipalGrant values.');
            }

            $capability = $grant->capability();
            $key = $capability->value() . "\0" . $grant->scope()->type() . "\0" . ($grant->scope()->identifier() ?? '');
            if (isset($normalized[$key])) {
                throw new InvalidArgumentException(sprintf('Principal grant %s occurs more than once.', $key));
            }

            $indexed[$capability->value()] = $capability;
            $normalized[$key] = $grant;
        }

        ksort($indexed, SORT_STRING);
        ksort($normalized, SORT_STRING);
        $this->capabilities = $indexed;
        $this->grants = array_values($normalized);
    }

    /** @param array<mixed> $capabilities */
    public static function issueFromStrings(
        object $provenance,
        string $subject,
        array $capabilities,
        ?string $credentialId = null,
        int $securityEpoch = 1,
    ): self
    {
        if (!array_is_list($capabilities)) {
            throw new InvalidArgumentException('Principal capability names must be a list.');
        }

        $values = [];

        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Principal capability names must be strings.');
            }

            $values[] = Capability::fromString($capability);
        }

        return new self(
            $provenance,
            strtolower($subject),
            $values,
            $credentialId ?? 'ephemeral:' . strtolower($subject),
            $securityEpoch,
        );
    }

    /**
     * @param list<array{capability: string, scope_type: string, scope_identifier: ?string}> $rows
     */
    public static function issueFromGrantRows(
        object $provenance,
        string $subject,
        array $rows,
        ?string $credentialId = null,
        int $securityEpoch = 1,
    ): self
    {
        $grants = [];

        foreach ($rows as $row) {
            $capability = Capability::fromString($row['capability']);
            $scope = $row['scope_type'] === 'global'
                ? GrantScope::global()
                : GrantScope::named($row['scope_type'], (string) $row['scope_identifier']);
            $grants[] = new PrincipalGrant($capability, $scope);
        }

        return new self(
            $provenance,
            strtolower($subject),
            $grants,
            $credentialId ?? 'ephemeral:' . strtolower($subject),
            $securityEpoch,
        );
    }

    public function subject(): string
    {
        return strtolower($this->subject);
    }

    public function hasCapability(Capability $capability): bool
    {
        return isset($this->capabilities[$capability->value()]);
    }

    /** @return list<Capability> */
    public function capabilities(): array
    {
        return array_values($this->capabilities);
    }

    /** @return list<PrincipalGrant> */
    public function grants(): array
    {
        return $this->grants;
    }

    /**
     * Binds idempotent results to the credential, current authorization epoch and
     * complete effective scoped-grant set, not merely to the user identifier.
     */
    public function authorizationFingerprint(): string
    {
        $grants = array_map(
            static fn (PrincipalGrant $grant): string => implode(':', [
                $grant->capability()->value(),
                $grant->scope()->type(),
                $grant->scope()->identifier() ?? '',
            ]),
            $this->grants,
        );

        return hash('sha256', implode("\n", [
            $this->subject(),
            $this->credentialId,
            (string) $this->securityEpoch,
            ...$grants,
        ]));
    }

    /** @param list<GrantScope> $requestedScopes */
    public function allows(Capability $capability, array $requestedScopes): bool
    {
        foreach ($this->grants as $grant) {
            if (!$grant->capability()->equals($capability)) {
                continue;
            }

            if ($grant->scope()->isGlobal()) {
                return true;
            }

            foreach ($requestedScopes as $scope) {
                if ($grant->scope()->covers($scope)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function context(
        \Kumwe\CMS\Application\Authorization\SiteContext $site,
        \Kumwe\CMS\Application\Authorization\AuthenticationStrength $authenticationStrength,
        string $requestId,
        ?string $correlationId = null,
    ): \Kumwe\CMS\Application\Authorization\ExecutionContext {
        return \Kumwe\CMS\Application\Authorization\ExecutionContext::issueHuman(
            $this->provenance,
            $this,
            $site,
            $authenticationStrength,
            $requestId,
            $correlationId,
        );
    }

    public function hasProvenance(object $provenance): bool
    {
        return $this->provenance === $provenance;
    }
}
