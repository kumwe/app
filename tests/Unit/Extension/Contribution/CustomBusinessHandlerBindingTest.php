<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\Extension\Contribution\CanonicalManifestInterpreter;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\OwnedExtensionBindingRegistrar;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionCommand;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessActionResult;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewHandler;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewQuery;
use Kumwe\Extension\Spi\BusinessSurface\Application\Custom\CustomBusinessViewResult;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalManifestInterpreter::class)]
#[CoversClass(OwnedExtensionBindingRegistrar::class)]
/**
 * Proves executable custom business handlers bind only to their signed policy contracts.
 *
 * @since  2.0.0
 */
final class CustomBusinessHandlerBindingTest extends TestCase
{
    /**
     * Prove signed view and action handlers register against their interpreted host contracts.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSignedViewAndActionHandlersBindToTheirContracts(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $manifest = self::manifest();
        $registrar = $registries->activateManifest($manifest);
        self::assertInstanceOf(OwnedExtensionBindingRegistrar::class, $registrar);

        $view = new class implements CustomBusinessViewHandler {
            /**
             * Serve one fixed probe document for the declared custom view contract.
             *
             * @param   CustomBusinessViewQuery  $query  Host-validated view query.
             *
             * @return  CustomBusinessViewResult  Fixed probe view document.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
            {
                unset($query);

                return new CustomBusinessViewResult(['heading' => 'probe', 'inspections' => []]);
            }
        };
        $action = new class implements CustomBusinessActionHandler {
            /**
             * Accept one fixed probe command for the declared custom action contract.
             *
             * @param   CustomBusinessActionCommand  $command  Host-validated action command.
             *
             * @return  CustomBusinessActionResult  Fixed probe action outcome.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessActionCommand $command): CustomBusinessActionResult
            {
                unset($command);

                return new CustomBusinessActionResult(['accepted' => true]);
            }
        };
        $registrar->customBusinessViewHandler(
            'kumwe.asset-inspection-example.views.inspection-risk-summary',
            $view,
        );
        $registrar->customBusinessActionHandler(
            'kumwe.asset-inspection-example.actions.reopen',
            $action,
        );

        $owner = DefinitionOwner::extension('kumwe/asset-inspection-example');
        self::assertNotSame([], $registries->customBusinessViewHandlers()->ownedBy($owner));
        self::assertNotSame([], $registries->customBusinessActionHandlers()->ownedBy($owner));
    }

    /**
     * Prove a handler identifier outside the signed declarations never registers.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUndeclaredHandlerIdentifierIsRefused(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $registrar = $registries->activateManifest(self::manifest());
        $view = new class implements CustomBusinessViewHandler {
            /**
             * Serve one fixed probe document for the declared custom view contract.
             *
             * @param   CustomBusinessViewQuery  $query  Host-validated view query.
             *
             * @return  CustomBusinessViewResult  Fixed probe view document.
             *
             * @since   2.0.0
             */
            public function handle(CustomBusinessViewQuery $query): CustomBusinessViewResult
            {
                unset($query);

                return new CustomBusinessViewResult([]);
            }
        };

        $this->expectException(LogicException::class);

        $registrar->customBusinessViewHandler('kumwe.asset-inspection-example.views.undeclared', $view);
    }

    /**
     * Parse the installable asset-inspection contribution graph with one added executable action.
     *
     * @return  ManifestContributions  Canonical signed declaration graph.
     *
     * @since   2.0.0
     */
    private static function manifest(): ManifestContributions
    {
        $document = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4) . '/examples/extensions/asset-inspection/kumwe.json',
            ),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($document);
        $contributions = $document['contributions'];
        $contributions['business']['action_handlers'][] = [
            'handler' => 'kumwe.asset-inspection-example.actions.reopen',
            'schema' => 'kumwe.asset-inspection-example.schemas.reopen-v1',
            'command_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['reason' => ['type' => 'string', 'maxLength' => 200]],
                'required' => ['reason'],
            ],
            'result_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['accepted' => ['type' => 'boolean']],
                'required' => ['accepted'],
            ],
        ];

        return ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('kumwe/asset-inspection-example'),
            $contributions,
            4,
        );
    }
}
