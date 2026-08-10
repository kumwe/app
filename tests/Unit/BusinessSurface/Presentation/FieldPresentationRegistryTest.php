<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessSurface\Presentation;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\CMS\BusinessSurface\Presentation\Field\CoreFieldPresenter;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentation;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationCoverage;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationRegistry;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresentationRequest;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\CMS\BusinessSurface\Presentation\Field\FieldWidget;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FieldPresentationContribution::class)]
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
        $presentation = new FieldPresentationContribution(
            $type->id,
            [FieldPresentationContext::Update, FieldPresentationContext::Detail],
        );
        $declarations = new ManifestContributionSet(
            ContributionOwner::extension('acme/editor'),
            fieldTypes: [$type],
            fieldPresentations: [$presentation],
        );
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/editor');
        $registrar = $registries->registrar($owner, $declarations);
        $registrar->fieldType($type);
        $registrar->fieldPresentation($presentation, new CoreFieldPresenter());
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
    public function testStrictRegistrarRejectsAlteredPresentationCoverage(): void
    {
        $type = self::type();
        $declared = new FieldPresentationContribution($type->id, [FieldPresentationContext::Detail]);
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar(
            ContributionOwner::extension('acme/editor'),
            new ManifestContributionSet(
                ContributionOwner::extension('acme/editor'),
                fieldTypes: [$type],
                fieldPresentations: [$declared],
            ),
        );
        $registrar->fieldType($type);

        $this->expectException(InvalidArgumentException::class);
        $registrar->fieldPresentation(
            new FieldPresentationContribution($type->id, [FieldPresentationContext::Create]),
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
    public function testFailedPresenterRegistrationRemainsOmitted(): void
    {
        $type = self::type();
        $presentation = new FieldPresentationContribution($type->id, [FieldPresentationContext::Detail]);
        $owner = ContributionOwner::extension('acme/editor');
        $registrar = (new ExtensionContributionRegistrySet(withCore: false))->registrar(
            $owner,
            new ManifestContributionSet(
                $owner,
                fieldTypes: [$type],
                fieldPresentations: [$presentation],
            ),
        );

        try {
            $registrar->fieldPresentation($presentation, new CoreFieldPresenter());
            self::fail('A presenter cannot be registered before its field type.');
        } catch (InvalidArgumentException) {
        }
        $registrar->fieldType($type);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('omitted declared field_presentation');
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
                 * @param   FieldPresentationRequest  $request  Server-authorized presentation request.
                 *
                 * @return  FieldPresentation  Maliciously writable semantic model.
                 *
                 * @since   2.0.0
                 */
                public function present(FieldPresentationRequest $request): FieldPresentation
                {
                    return new FieldPresentation(
                        $request->field->handle,
                        $request->field->label,
                        $request->context,
                        FieldWidget::Text,
                        '',
                        $request->value,
                        true,
                        $request->field->required,
                        $request->errors,
                    );
                }
            },
        );

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('cannot widen server-side editability');
        $registry->present(new FieldPresentationRequest(
            $field,
            $type,
            FieldPresentationContext::Update,
            'retained',
            editable: true,
        ));
    }

    /**
     * Schema 3 parses and canonically round-trips an actual signed presentation declaration.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSchemaThreeManifestRoundTripsPresentationDeclaration(): void
    {
        $type = self::type();
        $document = self::manifestDocument($type);
        $declared = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            $document,
            3,
        );
        $roundTrip = $declared->toArray();

        self::assertArrayHasKey('field_presentations', $roundTrip['business']);
        self::assertSame(
            [['field_type' => $type->id, 'contexts' => ['detail', 'update']]],
            $roundTrip['business']['field_presentations'],
        );
        self::assertSame(
            $roundTrip,
            ManifestContributionSet::fromManifest(
                ExtensionIdentifier::fromString('acme/editor'),
                $roundTrip,
                3,
            )->toArray(),
        );
    }

    /**
     * Schema 2 keeps its original closed business-contribution grammar.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSchemaTwoRejectsFieldPresentationDeclaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown key field_presentations');

        ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            self::manifestDocument(self::type()),
            2,
        );
    }

    /**
     * A published generated definition cannot defer missing custom presentation coverage until first render.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testManifestRejectsPublishedCustomFieldWithIncompletePresentationCoverage(): void
    {
        $type = self::type();
        $document = self::manifestDocument($type);
        $document['business']['definitions'] = [self::definitionDocument($type)];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('require signed presentation contexts');
        ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            $document,
            3,
        );
    }

    /**
     * Exact declarative coverage admits a published custom field before any provider code can run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testManifestAcceptsPublishedCustomFieldWithCompletePresentationCoverage(): void
    {
        $type = self::type();
        $document = self::manifestDocument($type);
        $document['business']['definitions'] = [self::definitionDocument($type)];
        $document['business']['field_presentations'][0]['contexts'] = [
            'relation',
            'update',
            'detail',
            'create',
            'list',
        ];

        $manifest = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            $document,
            3,
        );

        self::assertSame(
            ['create', 'detail', 'list', 'relation', 'update'],
            array_map(
                static fn (FieldPresentationContext $context): string => $context->value,
                $manifest->fieldPresentations()[0]->contexts,
            ),
        );
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
     * Schema 2 still round-trips an unused custom field type without admitting a schema-3-only key.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSchemaTwoRetainsUnusedCustomFieldTypeGrammar(): void
    {
        $type = self::type();
        $document = self::manifestDocument($type);
        unset($document['business']['field_presentations']);

        $roundTrip = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            $document,
            2,
        )->toArray();

        self::assertSame([$type->toArray()], $roundTrip['business']['field_types']);
        self::assertArrayNotHasKey('field_presentations', $roundTrip['business']);
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
