<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Preference;

use Kumwe\CMS\InterfaceStandard\CustomizationScope;
use Kumwe\CMS\InterfaceStandard\PresentationPreferenceValue;

/**
 * Safe effective value plus non-sensitive provenance and fallback diagnostics.
 *
 * @since  2.0.0
 */
final readonly class PresentationPreferenceResolution
{
    /**
     * Hold one resolved value without exposing stored audit attribution to renderers.
     *
     * @param  PresentationPreferenceValue  $value        Effective slot value.
     * @param  ?CustomizationScope          $source       Winning layer, or null for the immutable KIS default.
     * @param  ?int                         $version      Winning row version, or null for the default.
     * @param  list<string>                 $diagnostics  Stable codes for stale values ignored during fallback.
     *
     * @since  2.0.0
     */
    public function __construct(
        public PresentationPreferenceValue $value,
        public ?CustomizationScope $source,
        public ?int $version,
        public array $diagnostics = [],
    ) {
    }

    /**
     * Report whether a stored layer replaced the immutable KIS default.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    public function customized(): bool
    {
        return $this->source !== null;
    }
}
