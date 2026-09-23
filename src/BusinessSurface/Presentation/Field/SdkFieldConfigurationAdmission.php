<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

use InvalidArgumentException;
use Kumwe\BusinessDefinition\Application\FieldConfigurationAdmission;
use Kumwe\BusinessSurface\Contract\Presentation\Field\FieldPresentationConfiguration;

/**
 * Admits a field's configuration through the extension SDK's portable presentation profile.
 *
 * `kumwe/business-definition` validates a definition without knowing which presentation contract the host
 * composes, so it asks the host through `FieldConfigurationAdmission` and ships no permissive default. This
 * adapter is the App's answer: a configuration is admissible exactly when
 * `FieldPresentationConfiguration::fromArray()` accepts it, which is the same profile
 * `FieldPresentationInputFactory` later hands to every field presenter. Admission and presentation therefore
 * agree by construction, and a definition that publishes can always be presented.
 *
 * @since  2.0.0
 */
final readonly class SdkFieldConfigurationAdmission implements FieldConfigurationAdmission
{
    /**
     * Refuse configuration the SDK presentation profile cannot transport.
     *
     * The SDK's refusal is left as the `InvalidArgumentException` the port names, so the validator can wrap
     * it into an `InvalidBusinessDefinition` that identifies the field. The configuration is never mutated:
     * the SDK sorts and encodes a copy.
     *
     * @param   array<string, mixed>  $configuration  Field configuration exactly as the definition declares it.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the object is too wide, a key is malformed, a value is nested
     *          or executable, or a string, a list or the canonical encoding exceeds the SDK budget.
     *
     * @since   2.0.0
     */
    public function assertAdmissible(array $configuration): void
    {
        FieldPresentationConfiguration::fromArray($configuration);
    }
}
