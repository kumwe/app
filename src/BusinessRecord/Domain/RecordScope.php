<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Domain;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\BusinessDefinition\Domain\ScopeMode;

final readonly class RecordScope
{
    private function __construct(
        public ScopeMode $mode,
        public ?string $siteIdentifier,
        public ?string $organizationIdentifier,
    ) {
    }

    public static function forDefinition(
        ScopeMode $mode,
        SiteContext $site,
        ?string $organizationIdentifier,
    ): self {
        if ($organizationIdentifier !== null) {
            $organizationIdentifier = trim($organizationIdentifier);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $organizationIdentifier) !== 1) {
                throw new InvalidArgumentException('The business-record organization identifier is invalid.');
            }
        }
        if (
            in_array($mode, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)
            && $organizationIdentifier === null
        ) {
            throw new InvalidArgumentException('This business definition requires an organization scope.');
        }
        if (
            !in_array($mode, [ScopeMode::Organization, ScopeMode::SiteOrganization], true)
            && $organizationIdentifier !== null
        ) {
            throw new InvalidArgumentException('This business definition does not accept an organization scope.');
        }

        return new self(
            $mode,
            in_array($mode, [ScopeMode::Site, ScopeMode::SiteOrganization], true)
                ? $site->identifier()
                : null,
            $organizationIdentifier,
        );
    }

    public static function reconstitute(
        ScopeMode $mode,
        ?string $siteIdentifier,
        ?string $organizationIdentifier,
    ): self {
        if (
            ($mode === ScopeMode::Installation && ($siteIdentifier !== null || $organizationIdentifier !== null))
            || ($mode === ScopeMode::Site && ($siteIdentifier === null || $organizationIdentifier !== null))
            || ($mode === ScopeMode::Organization && ($siteIdentifier !== null || $organizationIdentifier === null))
            || ($mode === ScopeMode::SiteOrganization
                && ($siteIdentifier === null || $organizationIdentifier === null))
        ) {
            throw new InvalidArgumentException('Stored business-record scope metadata is inconsistent.');
        }

        return new self($mode, $siteIdentifier, $organizationIdentifier);
    }

    public function assertRequest(SiteContext $site, ?string $organizationIdentifier): void
    {
        $requested = self::forDefinition($this->mode, $site, $organizationIdentifier);
        if (
            $requested->siteIdentifier !== $this->siteIdentifier
            || $requested->organizationIdentifier !== $this->organizationIdentifier
        ) {
            throw new InvalidArgumentException('The request scope does not match the business record scope.');
        }
    }

    /** @return array{mode: string, site: ?string, organization: ?string} */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'site' => $this->siteIdentifier,
            'organization' => $this->organizationIdentifier,
        ];
    }
}
