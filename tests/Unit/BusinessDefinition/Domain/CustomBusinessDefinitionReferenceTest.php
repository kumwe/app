<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessDefinition\Domain;

use Kumwe\App\BusinessDefinition\Domain\ActionDefinition;
use Kumwe\App\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessDefinition\Domain\ViewDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActionDefinition::class)]
#[CoversClass(ViewDefinition::class)]
/**
 * Pins optional custom handler references without changing legacy definition bytes.
 *
 * @since  2.0.0
 */
final class CustomBusinessDefinitionReferenceTest extends TestCase
{
    /**
     * Proves absent custom references remain absent across canonical round trips.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLegacyViewAndActionCanonicalBytesRemainUnchanged(): void
    {
        $view = new ViewDefinition('list', 'Assets', 'list', ['name']);
        $action = new ActionDefinition('archive', 'Archive', 'business.record.archive');
        $viewDocument = $view->toArray();
        $actionDocument = $action->toArray();
        $before = CanonicalDefinitionJson::checksum([$viewDocument, $actionDocument]);

        $viewDocument['handler'] = null;
        $viewDocument['schema'] = null;
        $actionDocument['handler'] = null;
        $actionDocument['schema'] = null;
        $roundTrip = [
            ViewDefinition::fromArray($viewDocument)->toArray(),
            ActionDefinition::fromArray($actionDocument)->toArray(),
        ];

        self::assertArrayNotHasKey('handler', $roundTrip[0]);
        self::assertArrayNotHasKey('schema', $roundTrip[0]);
        self::assertArrayNotHasKey('handler', $roundTrip[1]);
        self::assertArrayNotHasKey('schema', $roundTrip[1]);
        self::assertSame($before, CanonicalDefinitionJson::checksum($roundTrip));
    }

    /**
     * Proves custom references round-trip and reject partial or ambiguous declarations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomReferencesArePairedBoundedAndMutuallyExclusiveWithTransitions(): void
    {
        $view = new ViewDefinition(
            'summary',
            'Summary',
            'detail',
            ['name'],
            handler: 'acme.editor.views.summary',
            schema: 'acme.editor.schemas.summary_v1',
        );
        $action = new ActionDefinition(
            'recalculate',
            'Recalculate',
            'acme.editor.manage',
            handler: 'acme.editor.actions.recalculate',
            schema: 'acme.editor.schemas.recalculate_v1',
        );

        self::assertSame($view->toArray(), ViewDefinition::fromArray($view->toArray())->toArray());
        self::assertSame($action->toArray(), ActionDefinition::fromArray($action->toArray())->toArray());

        foreach (
            [
            static fn (): ViewDefinition => new ViewDefinition(
                'summary',
                'Summary',
                'detail',
                ['name'],
                handler: 'acme.editor.views.summary',
            ),
            static fn (): ActionDefinition => new ActionDefinition(
                'recalculate',
                'Recalculate',
                'acme.editor.manage',
                transition: 'approved',
                handler: 'acme.editor.actions.recalculate',
                schema: 'acme.editor.schemas.recalculate_v1',
            ),
            ] as $invalid
        ) {
            try {
                $invalid();
                self::fail('An incomplete or ambiguous custom handler declaration was accepted.');
            } catch (InvalidBusinessDefinition) {
            }
        }
    }
}
