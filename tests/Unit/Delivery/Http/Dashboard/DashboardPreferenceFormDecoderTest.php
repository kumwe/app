<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Http\Dashboard;

use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Dashboard\DashboardPreferenceMutation;
use Kumwe\App\Delivery\Http\Dashboard\DashboardPreferenceFormDecoder;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies strict translation of flat browser fields into dashboard preference commands.
 *
 * @since  2.0.0
 */
#[CoversClass(DashboardPreferenceFormDecoder::class)]
#[UsesClass(DashboardPreferenceMutation::class)]
final class DashboardPreferenceFormDecoderTest extends TestCase
{
    /**
     * Proves a save reconstructs selected identifiers by explicit numeric order.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testDecodesAnOrderedSaveCommand(): void
    {
        $mutation = (new DashboardPreferenceFormDecoder())->decode([
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'expected_version' => '3',
            'item_0' => 'core.dashboard.content-summary',
            'selected_0' => '1',
            'order_0' => '20',
            'item_1' => 'core.settings',
            'selected_1' => '1',
            'order_1' => '10',
        ]);

        self::assertSame(CustomizationSlot::DashboardCards, $mutation->slot);
        self::assertSame(CustomizationScope::User, $mutation->scope);
        self::assertSame(3, $mutation->expectedVersion);
        self::assertFalse($mutation->reset);
        self::assertSame(
            ['core.dashboard.content-summary', 'core.settings'],
            $mutation->submittedIds,
        );
        self::assertSame(['core.settings', 'core.dashboard.content-summary'], $mutation->selectedIds);
    }

    /**
     * Proves reset carries no stale browser selection into the application command.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testResetIgnoresStaleItemFieldsAfterValidatingFlatStrings(): void
    {
        $mutation = (new DashboardPreferenceFormDecoder())->decode([
            'action' => 'navigation-shortcuts.reset',
            'scope' => 'role-workspace',
            'scope_id' => 'role:018f22e2-7c8b-7ab0-8f3a-88e8026bb303',
            'expected_version' => '2',
            'item_stale' => 'removed.navigation',
        ]);

        self::assertSame(CustomizationSlot::NavigationShortcuts, $mutation->slot);
        self::assertTrue($mutation->reset);
        self::assertSame([], $mutation->submittedIds);
        self::assertSame([], $mutation->selectedIds);
    }

    /**
     * Proves ambiguous action, version, index, selection, and order encodings fail before application execution.
     *
     * @param   array<string, string>  $form  Malformed flat browser fields.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('malformedForms')]
    public function testRejectsAmbiguousProtocolFields(array $form): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new DashboardPreferenceFormDecoder())->decode($form);
    }

    /**
     * Supply malformed protocol cases for every decoder-owned reconstruction concern.
     *
     * @return  iterable<string, array{array<string, string>}>  Named invalid forms.
     *
     * @since  2.0.0
     */
    public static function malformedForms(): iterable
    {
        $base = [
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'expected_version' => '0',
        ];
        yield 'action' => [[...$base, 'action' => 'dashboard-cards.publish']];
        yield 'version syntax' => [[...$base, 'expected_version' => '01']];
        yield 'version range' => [[
            ...$base,
            'expected_version' => str_repeat('9', strlen((string) PHP_INT_MAX)),
        ]];
        yield 'index syntax' => [[
            ...$base,
            'item_00' => 'core.settings',
            'order_00' => '1',
        ]];
        yield 'index gap' => [[
            ...$base,
            'item_1' => 'core.settings',
            'order_1' => '1',
        ]];
        yield 'missing order' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => '1',
        ]];
        yield 'selection flag' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => 'yes',
            'order_0' => '1',
        ]];
        yield 'duplicate selected order' => [[
            ...$base,
            'item_0' => 'core.settings',
            'selected_0' => '1',
            'order_0' => '1',
            'item_1' => 'core.access',
            'selected_1' => '1',
            'order_1' => '1',
        ]];
    }

    /**
     * Proves one form cannot make the decoder reconstruct more than its fixed catalogue budget.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testRejectsMoreThanTwoHundredFiftySixItems(): void
    {
        $form = [
            'action' => 'dashboard-cards.save',
            'scope' => 'user',
            'scope_id' => '018f22e2-7c8b-7ab0-8f3a-88e8026bb301',
            'expected_version' => '0',
        ];
        for ($index = 0; $index <= 256; $index++) {
            $form['item_' . $index] = 'core.item-' . $index;
            $form['order_' . $index] = (string) ($index + 1);
        }
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('out of range');

        (new DashboardPreferenceFormDecoder())->decode($form);
    }
}
