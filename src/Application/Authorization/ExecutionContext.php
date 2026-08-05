<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Authorization;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;

final readonly class ExecutionContext
{
    public const REQUEST_ATTRIBUTE = self::class;

    private function __construct(
        private object $provenance,
        private ?AuthenticatedPrincipal $principal,
        private ?SystemIdentity $systemIdentity,
        private SiteContext $site,
        private AuthenticationStrength $authenticationStrength,
        private string $requestId,
        private string $correlationId,
    ) {
        if (($principal === null) === ($systemIdentity === null)) {
            throw new InvalidArgumentException(
                'An execution context must contain exactly one human or system identity.',
            );
        }

        if ($principal !== null && $authenticationStrength === AuthenticationStrength::System) {
            throw new InvalidArgumentException('A human execution context cannot use system authentication.');
        }

        if ($systemIdentity !== null && $authenticationStrength !== AuthenticationStrength::System) {
            throw new InvalidArgumentException('A system execution context must use system authentication.');
        }

        self::assertIdentity($requestId, 'request');
        self::assertIdentity($correlationId, 'correlation');
    }

    public static function issueHuman(
        object $provenance,
        AuthenticatedPrincipal $principal,
        SiteContext $site,
        AuthenticationStrength $authenticationStrength,
        string $requestId,
        ?string $correlationId = null,
    ): self {
        if (!$principal->hasProvenance($provenance)) {
            throw new InvalidArgumentException('A human context requires a principal from the same authority.');
        }

        return new self(
            $provenance,
            $principal,
            null,
            $site,
            $authenticationStrength,
            $requestId,
            $correlationId ?? $requestId,
        );
    }

    public static function issueSystem(
        object $provenance,
        SystemIdentity $identity,
        SiteContext $site,
        string $requestId,
        ?string $correlationId = null,
    ): self {
        return new self(
            $provenance,
            null,
            $identity,
            $site,
            AuthenticationStrength::System,
            $requestId,
            $correlationId ?? $requestId,
        );
    }

    public function principal(): ?AuthenticatedPrincipal
    {
        return $this->principal;
    }

    public function systemIdentity(): ?SystemIdentity
    {
        return $this->systemIdentity;
    }

    public function site(): SiteContext
    {
        return $this->site;
    }

    public function authenticationStrength(): AuthenticationStrength
    {
        return $this->authenticationStrength;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function actorId(): string
    {
        return $this->principal?->subject() ?? $this->systemIdentity->value
            ?? throw new \LogicException('The execution context has no identity.');
    }

    public function authorizationFingerprint(): string
    {
        $identity = $this->principal?->authorizationFingerprint()
            ?? 'system:' . $this->systemIdentity->value;

        return hash('sha256', implode("\n", [
            $identity,
            $this->site->identifier(),
            $this->authenticationStrength->value,
        ]));
    }

    public function hasProvenance(object $provenance): bool
    {
        return $this->provenance === $provenance
            && ($this->principal === null || $this->principal->hasProvenance($provenance));
    }

    public function child(string $requestId, ?string $correlationId = null): self
    {
        return new self(
            $this->provenance,
            $this->principal,
            $this->systemIdentity,
            $this->site,
            $this->authenticationStrength,
            $requestId,
            $correlationId ?? $this->correlationId,
        );
    }

    private static function assertIdentity(string $value, string $name): void
    {
        if ($value === '' || strlen($value) > 191 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException(sprintf('The %s identity is invalid.', $name));
        }
    }
}
