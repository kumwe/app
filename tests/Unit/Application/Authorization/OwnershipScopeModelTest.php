<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use InvalidArgumentException;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\OwnershipScope;
use Kumwe\Access\OwnershipScopeLevel;
use Kumwe\Access\OwnershipScopeNotPermitted;
use Kumwe\Access\OwnershipScopeRule;
use Kumwe\Access\ResourceOwnership;
use Kumwe\Access\SiteGroup;
use Kumwe\App\Application\Authorization\HostAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the reserved ownership table this build hands the package scope policy and the categories it admits.
 *
 * The scope shapes, containment and rule mechanics are owned by kumwe/access-control; what stays here is
 * the exact host table and what it means: accounting is isolated by design, shared master data may be
 * widened, every category still admits a site owner, an unknown category stays isolated, and nothing
 * loaded later can reclassify a reserved category.
 *
 * @since  2.0.0
 */
#[CoversClass(HostAccessPolicy::class)]
final class OwnershipScopeModelTest extends TestCase
{
    /**
     * The host table fixes exactly forty-four categories and the policy built from it answers them all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheReservedTableIsTheFortyFourCategoriesThisBuildFixes(): void
    {
        $reserved = HostAccessPolicy::reservedOwnershipRules();
        $expected = $reserved;
        ksort($expected, SORT_STRING);

        self::assertCount(44, $reserved);
        self::assertSame($expected, HostAccessPolicy::ownershipScopePolicy()->table());
        foreach ($reserved as $category => $rule) {
            self::assertSame($rule, OwnershipScopeRule::from($rule->value), $category);
        }
    }

    /**
     * The books of a legal entity cannot be assembled into a shared owner at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccountingCategoriesCannotBeConstructedAtGroupScope(): void
    {
        $policy = HostAccessPolicy::ownershipScopePolicy();
        $group = OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', [
            'manufacturing',
            'retail',
        ]));

        foreach (['accounting_document', 'ledger', 'pay_run'] as $category) {
            self::assertSame(OwnershipScopeRule::SiteOnly, $policy->rule($category));
            try {
                ResourceOwnership::of(
                    AuthorizationResource::item($category, '018f22e2-7c8b-7ab0-8f3a-88e8026bb601'),
                    $group,
                    $policy,
                );
                self::fail(sprintf('A %s must never be owned by a group.', $category));
            } catch (OwnershipScopeNotPermitted $refused) {
                self::assertStringContainsString($category, $refused->getMessage());
            }
        }
    }

    /**
     * Shared master data is admitted at group scope, which is what makes sharing opt-in rather than absent.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSharedMasterDataCategoriesAreAdmittedAtGroupScope(): void
    {
        $policy = HostAccessPolicy::ownershipScopePolicy();
        $group = OwnershipScope::group(new SiteGroup('kumwe-group', 'Kumwe group', ['manufacturing']));

        foreach (['client', 'person', 'price_list', 'product_service'] as $category) {
            self::assertSame(OwnershipScopeRule::SiteOrGroup, $policy->rule($category));
            $owner = ResourceOwnership::of(
                AuthorizationResource::item($category, '018f22e2-7c8b-7ab0-8f3a-88e8026bb602'),
                $group,
                $policy,
            );
            self::assertSame($group, $owner->scope);
        }
    }

    /**
     * Every category admits the site level, so nothing this build carries becomes uncreatable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDeclaredCategoryStillAdmitsASiteOwner(): void
    {
        $policy = HostAccessPolicy::ownershipScopePolicy();

        foreach ($policy->table() as $category => $rule) {
            self::assertTrue(
                $rule->permits(OwnershipScopeLevel::Site),
                sprintf('Category %s must remain ownable by one site.', $category),
            );
        }
    }

    /**
     * A category nobody declared is isolated rather than shareable.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUndeclaredCategoryFallsBackToIsolation(): void
    {
        self::assertSame(
            OwnershipScopeRule::SiteOnly,
            HostAccessPolicy::ownershipScopePolicy()->rule('some_extension_category'),
        );
    }

    /**
     * A category this build reserves cannot be reclassified by anything that loads later.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReservedCategoriesCannotBeRedeclaredByAContribution(): void
    {
        $policy = HostAccessPolicy::ownershipScopePolicy();

        foreach (['ledger', 'pay_run', 'accounting_document', 'client'] as $category) {
            try {
                $policy->register($category, OwnershipScopeRule::SiteGroupOrInstallation);
                self::fail(sprintf('Category %s must not be redeclarable.', $category));
            } catch (InvalidArgumentException $refused) {
                self::assertStringContainsString($category, $refused->getMessage());
            }
        }
    }
}
