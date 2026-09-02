<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Application\Authorization;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\App\Application\Authorization\AuthorizationResource;
use Kumwe\App\Application\Authorization\CapabilityDefinition;
use Kumwe\App\Application\Authorization\CapabilityDefinitionRegistry;
use Kumwe\App\Application\Authorization\ResourcePolicyDefinition;
use Kumwe\App\Application\Authorization\ResourcePolicyRegistry;
use Kumwe\App\Application\Authorization\ResourcePolicyTarget;
use Kumwe\Extension\Spi\Identity\Domain\Capability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the policy registry binds a policy only to a capability its own owner registered.
 *
 * @since  2.0.0
 */
#[CoversClass(ResourcePolicyRegistry::class)]
final class ResourcePolicyRegistryTest extends TestCase
{
    /**
     * An extension policy is refused when it names a core capability or one nobody has registered.
     *
     * The registry consults the live capability catalog, so `acme/editor` can neither attach policy to
     * `content.read` nor to a capability that is not yet published, and the refusal names the owner
     * whose capability the policy must reference.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionPolicyMustReferenceACapabilityItsOwnerOwns(): void
    {
        $capabilities = new CapabilityDefinitionRegistry();
        $capabilities->register(self::capability('content.read', 'core'));
        $capabilities->register(self::capability('acme.editor.manage', 'acme/editor'));
        $registry = new ResourcePolicyRegistry($capabilities);

        try {
            $registry->register(self::policy('acme.editor.foreign', 'acme/editor', 'content.read', 'content'));
            self::fail('An extension cannot bind policy to a foreign capability.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Resource policy acme.editor.foreign must reference a capability owned by acme/editor.',
                $exception->getMessage(),
            );
        }

        try {
            $registry->register(self::policy('acme.editor.unknown', 'acme/editor', 'acme.editor.review', 'content'));
            self::fail('An extension cannot bind policy to a capability nobody registered.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Resource policy acme.editor.unknown must reference a capability owned by acme/editor.',
                $exception->getMessage(),
            );
        }

        self::assertSame([], $registry->ownedBy('acme/editor'));
        self::assertNull($registry->definitionFor(
            Capability::fromString('content.read'),
            AuthorizationResource::item('content', 'page'),
        ));
    }

    /**
     * A policy naming its owner's own capability registers, resolves, and is withdrawn with the owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPolicyBoundToAnOwnedCapabilityRegistersAndLeavesWithItsOwner(): void
    {
        $capabilities = new CapabilityDefinitionRegistry();
        $capabilities->register(self::capability('acme.editor.manage', 'acme/editor'));
        $registry = new ResourcePolicyRegistry($capabilities);
        $action = Capability::fromString('acme.editor.manage');
        $resource = AuthorizationResource::item('acme_editor_record', 'record-1');

        $registry->register(self::policy(
            'acme.editor.records',
            'acme/editor',
            'acme.editor.manage',
            'acme_editor_record',
        ));

        self::assertSame('acme.editor.records', $registry->definitionFor($action, $resource)?->id);
        self::assertCount(1, $registry->definitionsFor($action));
        self::assertSame('acme.editor.records', $registry->ownedBy('acme/editor')[0]->id);

        $registry->removeOwner('acme/editor');

        self::assertNull($registry->definitionFor($action, $resource));
        self::assertSame([], $registry->definitionsFor($action));
        self::assertSame([], $registry->ownedBy('acme/editor'));
    }

    /**
     * Build one active, delegatable capability definition for the given owner.
     *
     * @param   string  $id     Capability code being published.
     * @param   string  $owner  `core` or the `vendor/name` publisher.
     *
     * @return  CapabilityDefinition  Active definition at version 1 allowing global and site grants.
     *
     * @since   2.0.0
     */
    private static function capability(string $id, string $owner): CapabilityDefinition
    {
        return new CapabilityDefinition(
            Capability::fromString($id),
            $owner,
            ['global', 'site'],
            true,
            false,
            AuthorizationDefinitionLifecycle::Active,
            1,
        );
    }

    /**
     * Build one active whole-family policy binding a capability to a resource type.
     *
     * @param   string  $id          Policy identifier under the owner's namespace.
     * @param   string  $owner       `core` or the `vendor/name` publisher.
     * @param   string  $capability  Capability code the policy binds.
     * @param   string  $type        Resource family the single target covers.
     *
     * @return  ResourcePolicyDefinition  Site-scoped active definition naming no system identity.
     *
     * @since   2.0.0
     */
    private static function policy(
        string $id,
        string $owner,
        string $capability,
        string $type,
    ): ResourcePolicyDefinition {
        return new ResourcePolicyDefinition(
            $id,
            $owner,
            Capability::fromString($capability),
            [new ResourcePolicyTarget($type)],
            false,
            [],
            AuthorizationDefinitionLifecycle::Active,
            1,
        );
    }
}
