<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Support;

use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\AuthorizationDecision;
use Kumwe\CMS\Application\Authorization\AuthorizationDecisionRecorder;
use Kumwe\CMS\Application\Authorization\AuthorizationPolicyRegistry;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\DenyByDefaultAuthorizationGateway;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnership;
use Kumwe\CMS\Application\Authorization\ResourceSiteOwnershipWriter;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;

final class AuthorizationContext
{
    public const SUBJECT = '018f22e2-7c8b-7ab0-8f3a-88e8026bb301';

    private static ?object $provenance = null;

    /** @param list<string> $capabilities */
    public static function human(
        array $capabilities,
        string $subject = self::SUBJECT,
        string $site = SiteContext::DEFAULT,
    ): ExecutionContext {
        return self::principal($capabilities, $subject)->context(
            SiteContext::fromString($site),
            AuthenticationStrength::BearerToken,
            'test-request-0001',
        );
    }

    /** @param list<string> $capabilities */
    public static function principal(array $capabilities, string $subject = self::SUBJECT): AuthenticatedPrincipal
    {
        return AuthenticatedPrincipal::issueFromStrings(self::provenance(), $subject, $capabilities);
    }

    /**
     * @param list<array{capability: string, scope_type: string, scope_identifier: ?string}> $grants
     */
    public static function principalFromGrantRows(
        array $grants,
        string $subject = self::SUBJECT,
    ): AuthenticatedPrincipal {
        return AuthenticatedPrincipal::issueFromGrantRows(self::provenance(), $subject, $grants);
    }

    public static function system(SystemIdentity $identity): SystemPrincipal
    {
        return SystemPrincipal::issue(self::provenance(), $identity);
    }

    public static function provenance(): object
    {
        return self::$provenance ??= new \stdClass();
    }

    public static function gateway(
        ?AuthorizationDecisionRecorder $recorder = null,
        ?ResourceSiteOwnership $ownership = null,
    ): DenyByDefaultAuthorizationGateway {
        return new DenyByDefaultAuthorizationGateway(
            self::provenance(),
            new AuthorizationPolicyRegistry(),
            $ownership ?? self::ownership(),
            $recorder ?? new class implements AuthorizationDecisionRecorder {
                public function record(
                    ExecutionContext $context,
                    Capability $action,
                    AuthorizationResource $resource,
                    AuthorizationDecision $decision,
                ): void {
                }
            },
        );
    }

    public static function ownership(string $site = SiteContext::DEFAULT): ResourceSiteOwnership
    {
        return new class ($site) implements ResourceSiteOwnership {
            public function __construct(private string $site)
            {
            }

            public function siteFor(AuthorizationResource $resource): SiteContext
            {
                return SiteContext::fromString($this->site);
            }
        };
    }

    public static function ownershipWriter(): ResourceSiteOwnershipWriter
    {
        return new class implements ResourceSiteOwnershipWriter {
            public function record(AuthorizationResource $resource, SiteContext $site): void
            {
            }

            public function remove(AuthorizationResource $resource, SiteContext $expectedSite): void
            {
            }
        };
    }

    public static function siteScoped(string $capability, string $site = SiteContext::DEFAULT): ExecutionContext
    {
        return self::principalFromGrantRows([[
            'capability' => $capability,
            'scope_type' => 'site',
            'scope_identifier' => $site,
        ]])->context(
            SiteContext::fromString($site),
            AuthenticationStrength::BearerToken,
            'test-request-scoped-0001',
        );
    }
}
