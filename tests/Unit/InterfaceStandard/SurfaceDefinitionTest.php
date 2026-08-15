<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\InterfaceStandard;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Contribution\ContributionDefinition;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\OwnedRuntimeContributionRegistry;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\InterfaceStandard\ConformanceDiagnostic;
use Kumwe\CMS\InterfaceStandard\ConformanceSeverity;
use Kumwe\CMS\InterfaceStandard\CustomizationPermission;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\IconName;
use Kumwe\CMS\InterfaceStandard\InterfaceStandardVersion;
use Kumwe\CMS\InterfaceStandard\ResourceName;
use Kumwe\CMS\InterfaceStandard\ResponsiveElement;
use Kumwe\CMS\InterfaceStandard\ResponsivePriority;
use Kumwe\CMS\InterfaceStandard\SurfaceActor;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceConformanceReport;
use Kumwe\CMS\InterfaceStandard\SurfaceConformanceValidator;
use Kumwe\CMS\InterfaceStandard\SurfaceConformanceViolation;
use Kumwe\CMS\InterfaceStandard\SurfaceDeclaration;
use Kumwe\CMS\InterfaceStandard\SurfaceDefinition;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use Kumwe\CMS\InterfaceStandard\SurfaceIntent;
use Kumwe\CMS\InterfaceStandard\SurfacePattern;
use Kumwe\CMS\InterfaceStandard\SurfaceState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConformanceDiagnostic::class)]
#[CoversClass(ConformanceSeverity::class)]
#[CoversClass(CustomizationPermission::class)]
#[CoversClass(CustomizationScope::class)]
#[CoversClass(CustomizationSlot::class)]
#[CoversClass(IconName::class)]
#[CoversClass(InterfaceStandardVersion::class)]
#[CoversClass(ResourceName::class)]
#[CoversClass(ResponsiveElement::class)]
#[CoversClass(ResponsivePriority::class)]
#[CoversClass(SurfaceActor::class)]
#[CoversClass(SurfaceArea::class)]
#[CoversClass(SurfaceConformanceReport::class)]
#[CoversClass(SurfaceConformanceValidator::class)]
#[CoversClass(SurfaceConformanceViolation::class)]
#[CoversClass(SurfaceDeclaration::class)]
#[CoversClass(SurfaceDefinition::class)]
#[CoversClass(SurfaceId::class)]
#[CoversClass(SurfaceIntent::class)]
#[CoversClass(SurfacePattern::class)]
#[CoversClass(SurfaceState::class)]
#[UsesClass(Capability::class)]
#[UsesClass(ContributionOwner::class)]
#[UsesClass(OwnedRuntimeContributionRegistry::class)]
/**
 * Pins strict parsing, semantic conformance, and existing-registry compatibility for KIS surfaces.
 *
 * @since  2.0.0
 */
final class SurfaceDefinitionTest extends TestCase
{
    /**
     * Core metadata is admitted as a deterministic typed contribution definition.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreSurfaceIsAdmittedAndExportsCanonicalMetadata(): void
    {
        $definition = SurfaceDefinition::fromArray(ContributionOwner::core(), self::coreDeclaration());

        self::assertInstanceOf(ContributionDefinition::class, $definition);
        self::assertSame('core.business-definitions.catalog', $definition->identifier());
        self::assertSame('kis-1.0', $definition->toArray()['standard']);
        self::assertSame('collection-workspace', $definition->toArray()['pattern']);
        self::assertSame(['business.record.browse', 'content.read'], $definition->toArray()['capabilities']);
        self::assertSame('schema', $definition->toArray()['icon']);
        self::assertSame(
            ['definition-identity', 'technical-owner'],
            array_column($definition->toArray()['responsive'], 'element'),
        );
    }

    /**
     * An extension-owned template surface uses the established owner-aware declarative registry lifecycle.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExtensionTemplateSurfaceUsesExistingOwnerBoundRegistry(): void
    {
        $owner = ContributionOwner::extension('acme/orders');
        $data = self::coreDeclaration();
        $data['surface'] = 'acme.orders.portal-settings';
        $data['area'] = 'template';
        $data['actor'] = 'portal';
        $data['intent'] = 'settings';
        $data['resource'] = 'portal-settings';
        $data['purpose'] = 'Configure the presentation of the order portal.';
        $data['pattern'] = 'settings-workspace';
        $data['capabilities'] = ['acme.orders.manage'];
        $data['states'] = ['default', 'error', 'permission-reduced'];
        $data['customization'] = [['slot' => 'theme-mode', 'scope' => 'user']];
        $definition = SurfaceDefinition::fromArray($owner, $data);
        $registry = new OwnedRuntimeContributionRegistry('interface surface');

        $registry->register($owner, $definition);

        self::assertSame([$definition->toArray()], $registry->ownedBy($owner));
        self::assertSame($definition, $registry->definition($owner, $definition->identifier()));
        $registry->remove($owner);
        self::assertSame([], $registry->ownedBy($owner));
    }

    /**
     * Proves customization ceilings follow each slot's legal scope order rather than a global rank.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomizationScopeCeilingUsesSlotSpecificLegalOrder(): void
    {
        foreach (CustomizationScope::cases() as $scope) {
            self::assertTrue(SurfaceConformanceValidator::allowsCustomizationAtOrBelow(
                CustomizationSlot::Density,
                CustomizationScope::User,
                $scope,
            ));
        }
        self::assertTrue(SurfaceConformanceValidator::allowsCustomizationAtOrBelow(
            CustomizationSlot::Columns,
            CustomizationScope::RoleWorkspace,
            CustomizationScope::Administrator,
        ));
        self::assertTrue(SurfaceConformanceValidator::allowsCustomizationAtOrBelow(
            CustomizationSlot::Columns,
            CustomizationScope::RoleWorkspace,
            CustomizationScope::RoleWorkspace,
        ));
        self::assertFalse(SurfaceConformanceValidator::allowsCustomizationAtOrBelow(
            CustomizationSlot::Columns,
            CustomizationScope::RoleWorkspace,
            CustomizationScope::Site,
        ));
        self::assertFalse(SurfaceConformanceValidator::allowsCustomizationAtOrBelow(
            CustomizationSlot::Columns,
            CustomizationScope::RoleWorkspace,
            CustomizationScope::User,
        ));
    }

    /**
     * Strict parsing refuses unversioned, unsupported, unknown, executable-shaped, and foreign metadata.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsafeOrUnknownDeclarationMetadataFailsClosed(): void
    {
        $cases = [];
        $unversioned = self::coreDeclaration();
        unset($unversioned['standard']);
        $cases[] = [ContributionOwner::core(), $unversioned];
        $unsupported = self::coreDeclaration();
        $unsupported['standard'] = 'kis-2.0';
        $cases[] = [ContributionOwner::core(), $unsupported];
        $markup = self::coreDeclaration();
        $markup['markup'] = '<form></form>';
        $cases[] = [ContributionOwner::core(), $markup];
        $sql = self::coreDeclaration();
        $sql['sql'] = 'SELECT * FROM business_records';
        $cases[] = [ContributionOwner::core(), $sql];
        $javascript = self::coreDeclaration();
        $javascript['javascript'] = 'run()';
        $cases[] = [ContributionOwner::core(), $javascript];
        $script = self::coreDeclaration();
        $script['purpose'] = '<script>run()</script>';
        $cases[] = [ContributionOwner::core(), $script];
        $paddedPurpose = self::coreDeclaration();
        $paddedPurpose['purpose'] = ' Find and manage business definitions. ';
        $cases[] = [ContributionOwner::core(), $paddedPurpose];
        $protocolPurpose = self::coreDeclaration();
        $protocolPurpose['purpose'] = 'javascript:runUnsafePresentation()';
        $cases[] = [ContributionOwner::core(), $protocolPurpose];
        $duplicateCustomization = self::coreDeclaration();
        $duplicateCustomization['customization'][] = $duplicateCustomization['customization'][0];
        $cases[] = [ContributionOwner::core(), $duplicateCustomization];
        $duplicateResponsive = self::coreDeclaration();
        $duplicateResponsive['responsive'][] = $duplicateResponsive['responsive'][0];
        $cases[] = [ContributionOwner::core(), $duplicateResponsive];
        $icon = self::coreDeclaration();
        $icon['icon'] = '../unsafe.svg';
        $cases[] = [ContributionOwner::core(), $icon];
        $cases[] = [ContributionOwner::extension('acme/orders'), self::coreDeclaration()];

        $rejectedCases = 0;
        foreach ($cases as [$owner, $data]) {
            try {
                SurfaceDefinition::fromArray($owner, $data);
                self::fail('Unsafe KIS declaration metadata must be refused.');
            } catch (InvalidArgumentException) {
                $rejectedCases++;
            }
        }

        self::assertSame(count($cases), $rejectedCases);
    }

    /**
     * The validator reports all incompatible actor, pattern, policy, state, customization, and responsive choices.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSemanticFailuresProduceCompleteStableDiagnostics(): void
    {
        $declaration = new SurfaceDeclaration(
            ContributionOwner::core(),
            SurfaceId::fromString('core.security.review'),
            InterfaceStandardVersion::Kis1,
            SurfaceArea::Administrator,
            SurfaceActor::Portal,
            SurfaceIntent::Review,
            ResourceName::fromString('security-operation'),
            'Review a security operation.',
            SurfacePattern::CollectionWorkspace,
            [],
            [SurfaceState::Error],
            [new CustomizationPermission(CustomizationSlot::Layout, CustomizationScope::User)],
            [
                new ResponsiveElement(
                    ResourceName::fromString('operation-impact'),
                    ResponsivePriority::Essential,
                    true,
                ),
            ],
            null,
        );
        $report = (new SurfaceConformanceValidator())->validate($declaration);
        $codes = array_column($report->toArray(), 'code');

        self::assertFalse($report->conforms());
        self::assertContains('kis.actor.area', $codes);
        self::assertContains('kis.pattern.intent', $codes);
        self::assertContains('kis.capability.required', $codes);
        self::assertContains('kis.state.default-required', $codes);
        self::assertContains('kis.customization.scope', $codes);
        self::assertContains('kis.responsive.essential-collapse', $codes);

        try {
            SurfaceDefinition::admit($declaration);
            self::fail('A non-conforming typed candidate must not become a contribution definition.');
        } catch (SurfaceConformanceViolation $exception) {
            self::assertSame($report->toArray(), $exception->report->toArray());
            self::assertStringContainsString('kis.pattern.intent', $exception->getMessage());
        }
    }

    /**
     * Public authentication entry points may live in portal or administrator areas without fake capabilities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPublicActorSupportsShellLoginAndTemplateDeclarations(): void
    {
        $data = self::coreDeclaration();
        $data['surface'] = 'core.portal.login';
        $data['area'] = 'portal';
        $data['actor'] = 'public';
        $data['intent'] = 'form';
        $data['resource'] = 'portal-session';
        $data['purpose'] = 'Sign in to the ordinary-user portal.';
        $data['pattern'] = 'focused-form';
        $data['capabilities'] = [];
        $data['states'] = ['default', 'error'];
        $data['customization'] = [];

        $definition = SurfaceDefinition::fromArray(ContributionOwner::core(), $data);

        self::assertSame('portal', $definition->declaration->area->value);
        self::assertSame('public', $definition->declaration->actor->value);
        self::assertSame([], $definition->toArray()['capabilities']);
    }

    /**
     * Collection intent explicitly covers empty, dense, error, and permission-reduced behavior.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIntentAndPolicyStateRequirementsCannotBeSilentlyOmitted(): void
    {
        $data = self::coreDeclaration();
        $data['states'] = ['default'];

        try {
            SurfaceDefinition::fromArray(ContributionOwner::core(), $data);
            self::fail('A collection without its required operational states must not be admitted.');
        } catch (SurfaceConformanceViolation $exception) {
            $codes = array_column($exception->report->toArray(), 'code');
            self::assertContains('kis.state.intent-required', $codes);
            self::assertContains('kis.state.permission-reduced-required', $codes);
        }
    }

    /**
     * Canonical complete core collection fixture used by parsing and admission tests.
     *
     * @return  array<string, mixed>  Strict KIS 1.0 semantic declaration.
     *
     * @since   2.0.0
     */
    private static function coreDeclaration(): array
    {
        return [
            'surface' => 'core.business-definitions.catalog',
            'standard' => 'kis-1.0',
            'area' => 'administrator',
            'actor' => 'administrator',
            'intent' => 'collection',
            'resource' => 'business-definition',
            'purpose' => 'Find and manage business definitions.',
            'pattern' => 'collection-workspace',
            'capabilities' => ['content.read', 'business.record.browse'],
            'states' => ['default', 'empty', 'sparse', 'dense', 'error', 'permission-reduced'],
            'customization' => [
                ['slot' => 'columns', 'scope' => 'user'],
                ['slot' => 'density', 'scope' => 'user'],
            ],
            'responsive' => [
                ['element' => 'technical-owner', 'priority' => 'secondary', 'may_collapse' => true],
                ['element' => 'definition-identity', 'priority' => 'essential', 'may_collapse' => false],
            ],
            'icon' => 'schema',
        ];
    }
}
