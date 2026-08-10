<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessActionContract;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessActionHandlerRegistry;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessReferenceRegistry;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessSchema;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewContract;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewHandlerRegistry;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\CMS\BusinessSurface\Application\Custom\CustomBusinessViewResult;
use Kumwe\CMS\Extension\Contribution\BusinessContributionSurface;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Contribution\OwnedExtensionContributionRegistrar;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BusinessContributionSurface::class)]
#[CoversClass(CustomBusinessActionHandlerRegistry::class)]
#[CoversClass(CustomBusinessReferenceRegistry::class)]
#[CoversClass(CustomBusinessViewHandlerRegistry::class)]
#[CoversClass(ExtensionContributionRegistrySet::class)]
#[CoversClass(ManifestContributionSet::class)]
#[CoversClass(OwnedExtensionContributionRegistrar::class)]
/**
 * Pins signed custom-handler publication, provider reconciliation, inventory, and owner removal.
 *
 * @since  2.0.0
 */
final class CustomBusinessContributionTest extends TestCase
{
    /**
     * Proves schema-3 declarations round-trip, reconcile typed handlers, and leave no executable lifecycle state.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomContractsPublishReconcileInventoryAndWithdrawWithTheirOwner(): void
    {
        $extension = ExtensionIdentifier::fromString('acme/editor');
        $declared = ManifestContributionSet::fromManifest($extension, self::manifestContributions());
        $roundTrip = $declared->toArray();
        $owner = ContributionOwner::extension('acme/editor');
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->registrar($owner, $declared);
        $registrar->businessDefinition($declared->businessDefinitions()[0]);
        $registrar->customBusinessViewHandler($declared->customBusinessViews()[0], self::viewHandler());
        $registrar->customBusinessActionHandler($declared->customBusinessActions()[0], self::actionHandler());
        $registrar->complete();

        self::assertSame(
            $roundTrip,
            ManifestContributionSet::fromManifest($extension, $roundTrip)->toArray(),
        );
        self::assertSame(
            'acme.editor.views.summary',
            $registries->inventory($owner)['business']['view_handlers'][0]['handler'],
        );
        self::assertSame(
            'acme.editor.actions.recalculate',
            $registries->inventory($owner)['business']['action_handlers'][0]['handler'],
        );
        self::assertNotNull($registries->customBusinessViewHandlers()->contract(
            $declared->businessDefinitions()[0]->owner,
            'acme.editor.views.summary',
            'acme.editor.schemas.summary_v1',
        ));

        $registries->remove($owner);

        self::assertSame([], $registries->inventory($owner)['business']['view_handlers']);
        self::assertSame([], $registries->inventory($owner)['business']['action_handlers']);
        self::assertNull($registries->customBusinessViewHandlers()->contract(
            $declared->businessDefinitions()[0]->owner,
            'acme.editor.views.summary',
            'acme.editor.schemas.summary_v1',
        ));
    }

    /**
     * Proves legacy contribution exports do not gain empty custom-handler keys.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLegacySchemaTwoAndSchemaOneContributionShapesRemainReadable(): void
    {
        $owner = ContributionOwner::extension('acme/editor');
        $legacySchemaTwo = (new ManifestContributionSet($owner))->toArray();
        $legacySchemaOne = ManifestContributionSet::legacy(
            ExtensionIdentifier::fromString('acme/editor'),
            ['acme.editor.manage'],
        )->toArray();

        foreach ([$legacySchemaTwo, $legacySchemaOne] as $document) {
            self::assertArrayNotHasKey('field_presentations', $document['business']);
            self::assertArrayNotHasKey('view_handlers', $document['business']);
            self::assertArrayNotHasKey('action_handlers', $document['business']);
        }
        self::assertSame(
            $legacySchemaTwo,
            ManifestContributionSet::fromManifest(
                ExtensionIdentifier::fromString('acme/editor'),
                $legacySchemaTwo,
                2,
            )->toArray(),
        );
        self::assertSame(
            $legacySchemaOne,
            ManifestContributionSet::fromManifest(
                ExtensionIdentifier::fromString('acme/editor'),
                $legacySchemaOne,
                3,
            )->toArray(),
        );
    }

    /**
     * Proves package metadata cannot escape ownership, use unsafe schemas, duplicate contracts, or dangle refs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testManifestRejectsOwnerEscapesUnsafeSchemasDuplicatesAndMissingContracts(): void
    {
        $documents = [];

        $foreign = self::manifestContributions();
        $foreign['business']['view_handlers'][0]['handler'] = 'other.package.views.summary';
        $documents[] = $foreign;

        $unsafe = self::manifestContributions();
        $unsafe['business']['view_handlers'][0]['query_schema']['$ref'] = 'https://example.test/schema';
        $documents[] = $unsafe;

        $duplicate = self::manifestContributions();
        $second = $duplicate['business']['view_handlers'][0];
        $second['handler'] = 'acme.editor.views.other';
        $duplicate['business']['view_handlers'][] = $second;
        $documents[] = $duplicate;

        $collision = self::manifestContributions();
        $collision['business']['action_handlers'][0]['schema'] = 'acme.editor.views.summary';
        $documents[] = $collision;

        $missing = self::manifestContributions();
        unset($missing['business']['view_handlers']);
        $documents[] = $missing;

        foreach ($documents as $document) {
            try {
                ManifestContributionSet::fromManifest(
                    ExtensionIdentifier::fromString('acme/editor'),
                    $document,
                );
                self::fail('An unsafe custom business contribution manifest was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * Proves strict provider reconciliation catches changed handler contracts and omitted registrations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testProviderCannotChangeOrOmitSignedCustomContracts(): void
    {
        $declared = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/editor'),
            self::manifestContributions(),
        );
        $owner = ContributionOwner::extension('acme/editor');
        $registrar = (new ExtensionContributionRegistrySet(withCore: false))->registrar($owner, $declared);
        $registrar->businessDefinition($declared->businessDefinitions()[0]);

        try {
            $registrar->customBusinessViewHandler(
                new CustomBusinessViewContract(
                    'acme.editor.views.summary',
                    'acme.editor.schemas.summary_v1',
                    self::emptySchema(),
                    self::emptySchema(),
                ),
                self::viewHandler(),
            );
            self::fail('Provider drift from the signed custom view contract was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('does not match', $exception->getMessage());
        }

        $registrar = (new ExtensionContributionRegistrySet(withCore: false))->registrar($owner, $declared);
        $registrar->businessDefinition($declared->businessDefinitions()[0]);
        $registrar->customBusinessViewHandler($declared->customBusinessViews()[0], self::viewHandler());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('omitted declared custom_business_action_handler');
        $registrar->complete();
    }

    /**
     * Build the manifest contribution object used throughout the suite.
     *
     * @return  array<string, mixed>  Valid schema-3 contribution document.
     *
     * @since   2.0.0
     */
    private static function manifestContributions(): array
    {
        return [
            'version' => 1,
            'business' => [
                'field_types' => [],
                'definitions' => [self::definition()->toArray()],
                'view_handlers' => [[
                    'handler' => 'acme.editor.views.summary',
                    'schema' => 'acme.editor.schemas.summary_v1',
                    'query_schema' => self::querySchema(),
                    'result_schema' => self::viewResultSchema(),
                ]],
                'action_handlers' => [[
                    'handler' => 'acme.editor.actions.recalculate',
                    'schema' => 'acme.editor.schemas.recalculate_v1',
                    'command_schema' => self::commandSchema(),
                    'result_schema' => self::actionResultSchema(),
                ]],
            ],
        ];
    }

    /**
     * Build one extension-owned entity that references both custom contracts.
     *
     * @return  EntityTypeDefinition  Valid draft definition for manifest parsing.
     *
     * @since   2.0.0
     */
    private static function definition(): EntityTypeDefinition
    {
        return EntityTypeDefinition::fromArray([
            'id' => '018f4f24-98d8-7ad4-8f3f-38c909178b6b',
            'owner' => ['type' => 'extension', 'identifier' => 'acme/editor'],
            'site' => 'default',
            'handle' => 'acme.editor.asset',
            'singular_label' => 'Asset',
            'plural_label' => 'Assets',
            'status' => 'draft',
            'definition_version' => 0,
            'storage_mode' => 'relational',
            'identity_strategy' => 'uuid',
            'scope' => 'site',
            'audit_enabled' => true,
            'revisions_enabled' => true,
            'fields' => [[
                'handle' => 'id',
                'label' => 'ID',
                'type' => 'core.uuid',
                'required' => true,
                'nullable' => false,
                'unique' => true,
                'indexed' => true,
                'immutable_after_create' => true,
                'server_only' => true,
                'read_only' => true,
            ]],
            'relationships' => [],
            'views' => [[
                'handle' => 'summary',
                'label' => 'Summary',
                'kind' => 'detail',
                'fields' => ['id'],
                'administrator' => true,
                'handler' => 'acme.editor.views.summary',
                'schema' => 'acme.editor.schemas.summary_v1',
            ]],
            'actions' => [[
                'handle' => 'recalculate',
                'label' => 'Recalculate',
                'capability' => 'acme.editor.manage',
                'administrator' => true,
                'handler' => 'acme.editor.actions.recalculate',
                'schema' => 'acme.editor.schemas.recalculate_v1',
            ]],
            'workflow' => null,
            'compatibility_metadata' => [],
            'administrator_exposure' => true,
            'portal_exposure' => false,
            'public_exposure' => false,
        ]);
    }

    /**
     * Build the view query schema used in the signed declaration.
     *
     * @return  array<string, mixed>  Closed one-field query schema.
     *
     * @since   2.0.0
     */
    private static function querySchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['term' => ['type' => 'string', 'maxLength' => 40]],
            'required' => ['term'],
        ];
    }

    /**
     * Build the view result schema used in the signed declaration.
     *
     * @return  array<string, mixed>  Closed bounded item-list schema.
     *
     * @since   2.0.0
     */
    private static function viewResultSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'maxItems' => 20,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'properties' => ['label' => ['type' => 'string', 'maxLength' => 120]],
                        'required' => ['label'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    /**
     * Build the action command schema used in the signed declaration.
     *
     * @return  array<string, mixed>  Closed action-input schema.
     *
     * @since   2.0.0
     */
    private static function commandSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['mode' => ['type' => 'string', 'maxLength' => 5]],
            'required' => ['mode'],
        ];
    }

    /**
     * Build the action result schema used in the signed declaration.
     *
     * @return  array<string, mixed>  Closed action-output schema.
     *
     * @since   2.0.0
     */
    private static function actionResultSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['status' => ['type' => 'string', 'maxLength' => 20]],
            'required' => ['status'],
        ];
    }

    /**
     * Build an empty closed schema used to prove provider drift.
     *
     * @return  CustomBusinessSchema  Closed schema accepting only an empty object.
     *
     * @since   2.0.0
     */
    private static function emptySchema(): CustomBusinessSchema
    {
        return new CustomBusinessSchema([
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [],
        ]);
    }

    /**
     * Build a typed view handler for registrar reconciliation.
     *
     * @return  CustomBusinessViewHandler  Handler whose behavior is not executed by this lifecycle test.
     *
     * @since   2.0.0
     */
    private static function viewHandler(): CustomBusinessViewHandler
    {
        return new class implements CustomBusinessViewHandler {
            /**
             * Return an empty bounded view result.
             *
             * @param   CustomBusinessViewQuery  $query  Query not executed in this contribution test.
             *
             * @return  CustomBusinessViewResult  Empty item collection.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
            {
                return new CustomBusinessViewResult(['items' => []]);
            }
        };
    }

    /**
     * Build a typed action handler for registrar reconciliation.
     *
     * @return  CustomBusinessActionHandler  Handler whose behavior is not executed by this lifecycle test.
     *
     * @since   2.0.0
     */
    private static function actionHandler(): CustomBusinessActionHandler
    {
        return new class implements CustomBusinessActionHandler {
            /**
             * Return a bounded result tied to the command operation.
             *
             * @param   CustomBusinessActionCommand  $command  Command not executed in this contribution test.
             *
             * @return  CustomBusinessActionResult  Versioned completed result.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessActionCommand $command): CustomBusinessActionResult
            {
                return new CustomBusinessActionResult(['status' => 'done'], 2, $command->idempotencyKey);
            }
        };
    }
}
