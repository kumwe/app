<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Presentation\Dashboard;

use InvalidArgumentException;
use Kumwe\App\Application\Presentation\Preference\PresentationAccessGroup;
use Kumwe\App\InterfaceStandard\CustomizationScope;
use Kumwe\App\InterfaceStandard\CustomizationSlot;
use Kumwe\App\InterfaceStandard\SurfaceId;

/**
 * Typed application command for one dashboard preference save or reset.
 *
 * Delivery reconstructs this value from its protocol. The application service still intersects every
 * submitted identifier with the current server-owned live catalogue before reaching audited persistence.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceMutation
{
    /**
     * Validate a protocol-neutral dashboard preference command.
     *
     * @param   CustomizationSlot   $slot             Dashboard cards or navigation shortcuts.
     * @param   CustomizationScope  $scope            Personal or canonical access-group layer.
     * @param   string              $scopeId          Actor UUID or stable `role:<uuid>` identity.
     * @param   int                 $expectedVersion  Zero for create or the observed positive version.
     * @param   bool                $reset            Whether the exact stored row is deleted.
     * @param   list<string>        $submittedIds     Complete semantic identifiers represented by the form.
     * @param   list<string>        $selectedIds      Checked identifiers in requested display order.
     *
     * @throws  InvalidArgumentException  When target, version, identifiers, or slot bounds are invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public CustomizationSlot $slot,
        public CustomizationScope $scope,
        public string $scopeId,
        public int $expectedVersion,
        public bool $reset,
        public array $submittedIds,
        public array $selectedIds,
    ) {
        if (!in_array($slot, [CustomizationSlot::DashboardCards, CustomizationSlot::NavigationShortcuts], true)) {
            throw new InvalidArgumentException('The dashboard preference slot is invalid.');
        }
        if (!in_array($scope, [CustomizationScope::User, CustomizationScope::RoleWorkspace], true)) {
            throw new InvalidArgumentException('The dashboard preference scope is invalid.');
        }
        if ($scopeId === '' || strlen($scopeId) > 191) {
            throw new InvalidArgumentException('The dashboard preference scope identity is invalid.');
        }
        if (
            $scope === CustomizationScope::RoleWorkspace
            && PresentationAccessGroup::roleIdFromIdentifier($scopeId) === null
        ) {
            throw new InvalidArgumentException('The dashboard access-group identity is invalid.');
        }
        if ($expectedVersion < 0 || $expectedVersion === PHP_INT_MAX || ($reset && $expectedVersion < 1)) {
            throw new InvalidArgumentException('The dashboard preference version is invalid.');
        }
        if ($reset) {
            if ($submittedIds !== [] || $selectedIds !== []) {
                throw new InvalidArgumentException('A dashboard preference reset cannot carry a selection.');
            }
            return;
        }
        $submitted = self::identifiers($submittedIds, 256, 'form');
        $selected = self::identifiers(
            $selectedIds,
            $slot === CustomizationSlot::DashboardCards ? 64 : 32,
            'selection',
        );
        if (array_diff_key($selected, $submitted) !== []) {
            throw new InvalidArgumentException('A selected dashboard preference item was not submitted.');
        }
    }

    /**
     * Validate and index one unique semantic identifier list.
     *
     * @param   list<string>  $identifiers  Candidate identifiers in protocol or selected order.
     * @param   int           $maximum      Maximum entries admitted by this list.
     * @param   string        $kind         Stable kind used in a refusal message.
     *
     * @return  array<string, true>  Exact identifier lookup.
     *
     * @throws  InvalidArgumentException  When the list is malformed, duplicated, or outside its bound.
     *
     * @since   2.0.0
     */
    private static function identifiers(array $identifiers, int $maximum, string $kind): array
    {
        if (!array_is_list($identifiers)) {
            throw new InvalidArgumentException(sprintf('A dashboard preference %s is malformed.', $kind));
        }
        if (count($identifiers) > $maximum) {
            throw new InvalidArgumentException(sprintf(
                'A dashboard preference %s exceeds the KIS limit.',
                $kind,
            ));
        }
        $result = [];
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier) || isset($result[$identifier])) {
                throw new InvalidArgumentException(sprintf(
                    'A dashboard preference %s contains an invalid item.',
                    $kind,
                ));
            }
            SurfaceId::fromString($identifier);
            $result[$identifier] = true;
        }

        return $result;
    }
}
