<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Preference;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Preference\PresentationAccessGroupCatalog;
use Kumwe\CMS\Application\Presentation\Preference\PresentationPreferencePolicy;
use Kumwe\CMS\Application\Presentation\Preference\PresentationPreferenceRepository;
use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreference;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceValue;
use Kumwe\CMS\InterfaceStandard\SurfaceArea;
use Kumwe\CMS\InterfaceStandard\SurfaceId;
use RuntimeException;

/**
 * Resolves KIS defaults and stored layers through the documented low-to-high precedence order.
 *
 * A stale owner or a slot removed by an upgraded surface is ignored with a stable diagnostic, revealing
 * the next valid lower layer. Invalid stored row structure still fails closed in the repository rather
 * than being treated as an ordinary compatibility fallback.
 *
 * @since  2.0.0
 */
final readonly class PresentationPreferenceResolver
{
    /**
     * Bind hierarchy reads to durable storage and the live surface declaration policy.
     *
     * @param  PresentationPreferenceRepository  $preferences  Versioned preference store.
     * @param  PresentationPreferencePolicy      $policy       Live owner, slot, and scope admission.
     *
     * @since  2.0.0
     */
    public function __construct(
        private PresentationPreferenceRepository $preferences,
        private PresentationPreferencePolicy $policy,
    ) {
    }

    /**
     * Resolve an effective value from KIS default through every applicable stored layer.
     *
     * @param   SurfaceId                      $surface       Semantic surface being rendered.
     * @param   ContributionOwner              $owner         Expected current contribution owner.
     * @param   CustomizationSlot              $slot          Presentation choice being resolved.
     * @param   mixed                          $defaultValue  Immutable KIS default for the slot.
     * @param   PresentationPreferenceContext  $context       Server-resolved site, area, role, and user.
     *
     * @return  PresentationPreferenceResolution  Effective safe value and fallback evidence.
     *
     * @throws  \InvalidArgumentException  When the supplied KIS default violates the slot vocabulary.
     * @throws  RuntimeException  When a repository returns a record for a different durable key.
     *
     * @since   2.0.0
     */
    public function resolve(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        mixed $defaultValue,
        PresentationPreferenceContext $context,
    ): PresentationPreferenceResolution {
        $value = PresentationPreferenceValue::from($slot, $defaultValue);
        $source = null;
        $version = null;
        $diagnostics = [];
        foreach ($context->layers($surface, $slot) as $key) {
            $preference = $this->admittedPreference($key, $owner, $diagnostics);
            if ($preference === null) {
                continue;
            }
            $value = $preference->value();
            $source = $key->scope;
            $version = $preference->version();
        }

        return new PresentationPreferenceResolution(
            $value,
            $source,
            $version,
            array_values(array_unique($diagnostics)),
        );
    }

    /**
     * Resolve one dashboard list while composing every server-resolved access-group default.
     *
     * Site and administrator layers retain ordinary whole-slot precedence. For a complete catalogue, the
     * current workspace and supplied role access groups are sorted by stable scope identifier and their valid
     * lists are unioned in that order. An incomplete catalogue contributes no projected-role prefix and emits
     * a stable diagnostic, while the complete current workspace and a valid user row retain ordinary precedence.
     * A synthetic multi-row aggregate has no single optimistic version; one contributing row retains its version.
     *
     * @param   SurfaceId                       $surface       Semantic dashboard surface being rendered.
     * @param   ContributionOwner               $owner         Expected current contribution owner.
     * @param   CustomizationSlot               $slot          Dashboard cards or navigation shortcuts.
     * @param   list<string>                    $defaultValue  Immutable ordered KIS default list.
     * @param   PresentationPreferenceContext   $context       Server-resolved site, area, workspace, and user.
     * @param   PresentationAccessGroupCatalog  $accessGroups  Bounded roles plus explicit completeness evidence.
     *
     * @return  PresentationPreferenceResolution  Effective bounded list and fallback evidence.
     *
     * @throws  InvalidArgumentException  When the slot is not a dashboard list or input violates its vocabulary.
     * @throws  RuntimeException  When a repository returns a record for a different durable key.
     *
     * @since   2.0.0
     */
    public function resolveListForAccessGroups(
        SurfaceId $surface,
        ContributionOwner $owner,
        CustomizationSlot $slot,
        array $defaultValue,
        PresentationPreferenceContext $context,
        PresentationAccessGroupCatalog $accessGroups,
    ): PresentationPreferenceResolution {
        $maximum = match ($slot) {
            CustomizationSlot::DashboardCards => 64,
            CustomizationSlot::NavigationShortcuts => 32,
            default => throw new InvalidArgumentException(
                'Access-group list composition is available only for dashboard cards and navigation shortcuts.',
            ),
        };
        $value = PresentationPreferenceValue::from($slot, $defaultValue);
        $source = null;
        $version = null;
        $diagnostics = [];

        $lowerKeys = [];
        $scopeIds = [];
        $userKey = null;
        foreach ($context->layers($surface, $slot) as $key) {
            if ($key->scope === CustomizationScope::RoleWorkspace) {
                if ($key->scopeId === null) {
                    throw new RuntimeException('A role/workspace preference key has no scope identity.');
                }
                $scopeIds[$key->scopeId] = true;
                continue;
            }
            if ($key->scope === CustomizationScope::User) {
                $userKey = $key;
                continue;
            }
            $lowerKeys[] = $key;
        }
        if ($accessGroups->hasNext()) {
            $diagnostics[] = 'kis.preference.access-group-catalog-incomplete';
        } elseif ($context->area !== SurfaceArea::Public) {
            foreach ($accessGroups->groups as $accessGroup) {
                $scopeIds[$accessGroup->id] = true;
            }
        }
        $scopeIds = array_keys($scopeIds);
        sort($scopeIds, SORT_STRING);

        $groupKeys = array_map(
            static fn (string $scopeId): PresentationPreferenceKey => new PresentationPreferenceKey(
                $surface,
                $slot,
                CustomizationScope::RoleWorkspace,
                $scopeId,
            ),
            $scopeIds,
        );
        $keys = [...$lowerKeys, ...$groupKeys];
        if ($userKey !== null) {
            $keys[] = $userKey;
        }
        $preferences = $this->admittedPreferences($keys, $owner, $diagnostics);

        foreach ($lowerKeys as $key) {
            $preference = $preferences[$key->auditSubjectId()] ?? null;
            if ($preference === null) {
                continue;
            }
            $value = $preference->value();
            $source = $key->scope;
            $version = $preference->version();
        }

        $union = [];
        $seen = [];
        $versions = [];
        foreach ($groupKeys as $key) {
            $preference = $preferences[$key->auditSubjectId()] ?? null;
            if ($preference === null) {
                continue;
            }
            $items = $preference->value()->value();
            if (!is_array($items) || !array_is_list($items)) {
                throw new RuntimeException('A stored KIS dashboard list did not hydrate as a list.');
            }
            foreach ($items as $item) {
                if (!is_string($item)) {
                    throw new RuntimeException('A stored KIS dashboard list item did not hydrate as a string.');
                }
                if (isset($seen[$item])) {
                    continue;
                }
                if (count($union) >= $maximum) {
                    $diagnostics[] = 'kis.preference.group-list-truncated';
                    continue;
                }
                $seen[$item] = true;
                $union[] = $item;
            }
            $versions[] = $preference->version();
        }
        if ($versions !== []) {
            $value = PresentationPreferenceValue::from($slot, $union);
            $source = CustomizationScope::RoleWorkspace;
            $version = count($versions) === 1 ? $versions[0] : null;
        }

        if ($userKey !== null) {
            $preference = $preferences[$userKey->auditSubjectId()] ?? null;
            if ($preference !== null) {
                $value = $preference->value();
                $source = CustomizationScope::User;
                $version = $preference->version();
            }
        }

        return new PresentationPreferenceResolution(
            $value,
            $source,
            $version,
            array_values(array_unique($diagnostics)),
        );
    }

    /**
     * Read and validate one bounded exact-key set through the repository batch boundary.
     *
     * Missing rows remain absent. Stale owners and removed slots contribute only stable diagnostics, while
     * an unexpected or duplicated durable identity is a repository contract violation and fails closed.
     *
     * @param   list<PresentationPreferenceKey>  $keys         Exact keys to read in precedence order.
     * @param   ContributionOwner                $owner        Expected active surface owner.
     * @param   list<string>                     $diagnostics  Compatibility findings accumulated by the caller.
     *
     * @return  array<string, PresentationPreference>  Admitted rows keyed by durable audit-subject identity.
     *
     * @throws  RuntimeException  When the repository returns an unrequested or inconsistent row.
     *
     * @since   2.0.0
     */
    private function admittedPreferences(array $keys, ContributionOwner $owner, array &$diagnostics): array
    {
        $requested = [];
        foreach ($keys as $key) {
            $identity = $key->auditSubjectId();
            if (isset($requested[$identity])) {
                throw new RuntimeException('A KIS preference batch contains a duplicate durable key.');
            }
            $requested[$identity] = $key;
        }

        $result = [];
        foreach ($this->preferences->findMany($keys) as $identity => $preference) {
            $key = $requested[$identity] ?? null;
            if (
                $key === null
                || isset($result[$identity])
                || !PresentationPreferenceKey::fromPreference($preference)->equals($key)
            ) {
                throw new RuntimeException('The KIS preference repository returned a record for another key.');
            }
            if ($preference->owner()->identifier() !== $owner->identifier()) {
                $diagnostics[] = 'kis.preference.owner-stale';
                continue;
            }
            if (!$this->policy->allows($key->surface, $owner, $key->slot, $key->scope)) {
                $diagnostics[] = 'kis.preference.slot-removed';
                continue;
            }
            $result[$identity] = $preference;
        }

        return $result;
    }

    /**
     * Read one exact row and apply live key, owner, slot, scope, and area compatibility checks.
     *
     * @param   PresentationPreferenceKey  $key          Complete durable layer identity.
     * @param   ContributionOwner          $owner        Expected active surface owner.
     * @param   list<string>               $diagnostics  Compatibility findings accumulated by the caller.
     *
     * @return  ?PresentationPreference  Live admissible row, or null after a safe compatibility fallback.
     *
     * @throws  RuntimeException  When the repository violates exact-key lookup semantics.
     *
     * @since   2.0.0
     */
    private function admittedPreference(
        PresentationPreferenceKey $key,
        ContributionOwner $owner,
        array &$diagnostics,
    ): ?PresentationPreference {
        $preference = $this->preferences->find($key);
        if ($preference === null) {
            return null;
        }
        if (!PresentationPreferenceKey::fromPreference($preference)->equals($key)) {
            throw new RuntimeException('The KIS preference repository returned a record for another key.');
        }
        if ($preference->owner()->identifier() !== $owner->identifier()) {
            $diagnostics[] = 'kis.preference.owner-stale';
            return null;
        }
        if (!$this->policy->allows($key->surface, $owner, $key->slot, $key->scope)) {
            $diagnostics[] = 'kis.preference.slot-removed';
            return null;
        }

        return $preference;
    }
}
