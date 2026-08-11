<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Demo\Application;

use Kumwe\CMS\Application\Authorization\SystemIdentity;
use Kumwe\CMS\Extension\Contribution\CoreExtensionContributions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the purpose-specific unattended authority used while reconciling built-in profiles.
 *
 * @since  2.0.0
 */
#[CoversClass(CoreExtensionContributions::class)]
final class DemoProfileAuthorityTest extends TestCase
{
    /**
     * Proves the installer has only fixture lifecycle authority and cannot administer the installation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProfileInstallerHasAnExactLeastAuthorityCapabilitySet(): void
    {
        $capabilities = [];
        foreach (CoreExtensionContributions::resourcePolicyDefinitions() as $policy) {
            if (in_array(SystemIdentity::ProfileInstaller, $policy->systemIdentities, true)) {
                $capabilities[] = $policy->capability;
            }
        }
        sort($capabilities, SORT_STRING);

        self::assertSame([
            'business.record.action',
            'business.record.archive',
            'business.record.browse',
            'business.record.create',
            'business.record.history',
            'business.record.read',
            'business.record.relate',
            'business.record.restore',
            'business.record.transition',
            'business.record.update',
            'business.schema.approve',
            'business.schema.execute',
            'business.schema.plan',
            'business.schema.read',
            'business.schema.recover',
            'content.archive',
            'content.create',
            'content.delete',
            'content.publish',
            'content.read',
            'content.restore',
            'content.submit',
            'content.update',
            'navigation.manage',
            'settings.manage',
        ], $capabilities);
        foreach (
            [
                'administrator.bootstrap',
                'automation.manage',
                'business.record.delete',
                'business.schema.destructive',
                'business.security.manage',
                'extensions.manage',
                'system.migrate',
                'themes.administrator.manage',
                'themes.site.manage',
                'users.manage',
            ] as $forbidden
        ) {
            self::assertNotContains($forbidden, $capabilities);
        }
    }
}
