<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Presentation;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessSurface\Presentation\Field\CoreFieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationInputFactory;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationInput;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationModel;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationCoverage;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRegistry;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldWidget;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldPresentationInputFactory::class)]
#[CoversClass(FieldPresentationCoverage::class)]
#[CoversClass(FieldPresentationRegistry::class)]
/**
 * Pins signed field-presentation reconciliation, owner isolation, inventory, and lifecycle removal.
 *
 * @since  2.0.0
 */
final class FieldPresentationRegistryTest extends TestCase
{
    /**
     * A strict provider can register only the exact contexts its manifest declared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedPresentationRegistersAndLeavesNoExecutableLifecycleState(): void
    {
        $type = self::type();
        $declarations = ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            self::manifestDocument($type),
            3,
        );
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/editor');
        $registrar = $registries->activateManifest($declarations);
        $registrar->fieldPresenter($type->id, new CoreFieldPresenter());
        $registrar->complete();

        self::assertSame(['detail', 'update'], $registries->fieldPresentations()->contexts($type->id));
        self::assertSame(
            [['field_type' => $type->id, 'contexts' => ['detail', 'update']]],
            $registries->inventory($owner)['business']['field_presentations'],
        );

        $registries->remove($owner);

        self::assertSame([], $registries->fieldPresentations()->contexts($type->id));
        self::assertSame([], $registries->inventory($owner)['business']['field_presentations']);
    }

    /**
     * A provider cannot change its signed context set at runtime.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalBindingRejectsAnUndeclaredFieldType(): void
    {
        $type = self::type();
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->activateManifest(ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            self::manifestDocument($type),
            3,
        ));

        $this->expectException(InvalidArgumentException::class);
        $registrar->fieldPresenter(
            'acme.editor.undeclared',
            new CoreFieldPresenter(),
        );
    }

    /**
     * A failed early presenter call cannot be caught to satisfy final manifest reconciliation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOmittedPresenterFailsExactManifestReconciliation(): void
    {
        $type = self::type();
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->activateManifest(ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            self::manifestDocument($type),
            3,
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('do not exactly satisfy');
        $registrar->complete();
    }

    /**
     * A collision discovered after an available context leaves no partial registration behind.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContextCollisionLeavesRegistryUnchanged(): void
    {
        $type = self::type();
        $owner = DefinitionOwner::extension('acme/editor');
        $registry = new FieldPresentationRegistry();
        $registry->register($owner, $type->id, [FieldPresentationContext::Detail], new CoreFieldPresenter());

        try {
            $registry->register(
                $owner,
                $type->id,
                [FieldPresentationContext::Create, FieldPresentationContext::Detail],
                new CoreFieldPresenter(),
            );
            self::fail('A colliding context set must be rejected atomically.');
        } catch (InvalidArgumentException) {
        }

        self::assertSame(['detail'], $registry->contexts($type->id));
    }

    /**
     * A safe extension presenter cannot turn a server-read-only field into an input control.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPresenterCannotWidenServerSideEditability(): void
    {
        $type = self::type();
        $field = new FieldDefinition('code', 'Code', $type->id, readOnly: true);
        $registry = new FieldPresentationRegistry();
        $registry->register(
            DefinitionOwner::extension('acme/editor'),
            $type->id,
            [FieldPresentationContext::Update],
            new class implements FieldPresenter {
                /**
                 * Return an internally valid editor while deliberately widening the request contract.
                 *
                 * @param   FieldPresentationInput  $request  Server-authorized presentation request.
                 *
                 * @return  FieldPresentationModel  Maliciously writable semantic model.
                 *
                 * @since   2.0.0
                 */
                public function present(FieldPresentationInput $request): FieldPresentationModel
                {
                    return new FieldPresentationModel(
                        $request->handle,
                        $request->label,
                        $request->context,
                        FieldWidget::Text,
                        '',
                        $request->value,
                        true,
                        $request->required,
                        $request->errors,
                    );
                }
            },
        );

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('cannot widen server-side editability');
        $registry->present(FieldPresentationInputFactory::fromDefinition(
            $field,
            $type,
            FieldPresentationContext::Update,
            'retained',
            editable: true,
        ));
    }

    /**
     * A published generated definition cannot defer missing custom presentation coverage until first render.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testIncompletePresentationCoverageIsRefusedAtActiveGraphAdmission(): void
    {
        $type = self::type();
        $document = self::manifestDocument($type);
        $document['business']['definitions'] = [self::definitionDocument($type)];
        $contributions = ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            $document,
            3,
        );
        self::assertIsArray(
            $contributions->declarations()['business'] ?? null,
            'The structural manifest boundary admits the declaration; coverage is host admission policy.',
        );

        $registries = new ExtensionContributionRegistrySet();
        $registries->fieldTypes()->register(DefinitionOwner::extension('acme/editor'), $type);
        $registries->businessDefinitions()->register(
            DefinitionOwner::extension('acme/editor'),
            EntityTypeDefinition::fromArray(self::definitionDocument($type)),
        );

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('Active business definitions require field-presentation contexts');
        $registries->validateBusinessDefinitions();
    }

    /**
     * Activation also checks a custom type whose signed presenter belongs to another contributing owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAssembledGraphRejectsMissingCrossOwnerPresentationCoverage(): void
    {
        $type = self::type();
        $document = self::definitionDocument($type);
        $document['owner'] = ['type' => 'extension', 'identifier' => 'consumer/forms'];
        $document['handle'] = 'consumer.forms.asset';
        $definition = EntityTypeDefinition::fromArray($document);
        $registries = new ExtensionContributionRegistrySet();
        $registries->fieldTypes()->register(DefinitionOwner::extension('acme/editor'), $type);
        $registries->businessDefinitions()->register(
            DefinitionOwner::extension('consumer/forms'),
            $definition,
        );

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('Active business definitions require field-presentation contexts');
        $registries->validateBusinessDefinitions();
    }

    /**
     * Build one extension-owned field type.
     *
     * @return  FieldTypeDefinition  Typed fixture.
     *
     * @since   2.0.0
     */
    private static function type(): FieldTypeDefinition
    {
        $type = new FieldTypeDefinition(
            'acme.editor.code',
            'Code',
            'A bounded extension-owned code.',
            'string',
            'string',
        );
        DefinitionOwner::extension('acme/editor')->assertOwns($type->id);

        return $type;
    }

    /**
     * Build a strict contribution document containing one extension-owned presenter declaration.
     *
     * @param   FieldTypeDefinition  $type  Field type the presenter covers.
     *
     * @return  array<string, mixed>  Valid schema-3 contribution payload.
     *
     * @since   2.0.0
     */
    private static function manifestDocument(FieldTypeDefinition $type): array
    {
        return [
            'version' => 1,
            'business' => [
                'field_types' => [$type->toArray()],
                'definitions' => [],
                'field_presentations' => [[
                    'field_type' => $type->id,
                    'contexts' => ['update', 'detail'],
                ]],
            ],
        ];
    }

    /**
     * Build a published generated definition whose custom field can reach all core browser contexts.
     *
     * @param   FieldTypeDefinition  $type  Package-owned field type used by the definition.
     *
     * @return  array<string, mixed>  Canonical published entity document.
     *
     * @since   2.0.0
     */
    private static function definitionDocument(FieldTypeDefinition $type): array
    {
        return [
            'id' => '01912f8a-8c4b-7eb1-8f7d-c256efd39899',
            'owner' => ['type' => 'extension', 'identifier' => 'acme/editor'],
            'site' => 'default',
            'handle' => 'acme.editor.asset',
            'singular_label' => 'Asset',
            'plural_label' => 'Assets',
            'status' => 'published',
            'definition_version' => 1,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [
                (new FieldDefinition(
                    'id',
                    'ID',
                    'core.uuid',
                    required: true,
                    nullable: false,
                    unique: true,
                    indexed: true,
                    immutableAfterCreate: true,
                    serverOnly: true,
                    readOnly: true,
                ))->toArray(),
                (new FieldDefinition('code', 'Code', $type->id))->toArray(),
            ],
            'relationships' => [],
            'views' => [],
            'actions' => [],
            'workflow' => null,
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ];
    }
}
