<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Preference;

use Kumwe\CMS\Extension\Contribution\ContributionOwner;
use Kumwe\CMS\InterfaceStandard\CustomizationSlot;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceKey;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceValue;
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
            $preference = $this->preferences->find($key);
            if ($preference === null) {
                continue;
            }
            if (!PresentationPreferenceKey::fromPreference($preference)->equals($key)) {
                throw new RuntimeException('The KIS preference repository returned a record for another key.');
            }
            if ($preference->owner()->identifier() !== $owner->identifier()) {
                $diagnostics[] = 'kis.preference.owner-stale';
                continue;
            }
            if (!$this->policy->allows($surface, $owner, $slot, $key->scope)) {
                $diagnostics[] = 'kis.preference.slot-removed';
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
}
