<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

/**
 * Deterministic semantic admission policy for Kumwe Interface Standard 1.0 surfaces.
 *
 * The validator operates only on typed declarations. It neither renders a surface nor decides
 * authorization; capability and field policy remain application concerns applied before rendering.
 *
 * @since  2.0.0
 */
final readonly class SurfaceConformanceValidator
{
    /**
     * Patterns each semantic intent may select under KIS 1.0.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const INTENT_PATTERNS = [
        'collection' => ['collection-workspace', 'master-detail-workspace'],
        'detail' => ['master-detail-workspace', 'tabs', 'local-navigation'],
        'form' => ['focused-form', 'drawer-form', 'step-flow'],
        'parent-child' => ['master-detail-workspace', 'inline-subform', 'child-collection'],
        'chooser' => ['resource-chooser'],
        'workflow' => ['step-flow', 'tabs', 'status-workspace'],
        'review' => ['review-confirmation'],
        'comparison' => ['comparison'],
        'monitor' => ['status-workspace'],
        'settings' => ['settings-workspace', 'tabs', 'local-navigation'],
        'diagnostics' => ['diagnostics-workspace', 'tabs'],
    ];

    /**
     * Configuration scopes allowed for each safe customization slot.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const CUSTOMIZATION_SCOPES = [
        'columns' => ['administrator', 'role-workspace', 'user'],
        'density' => ['site', 'administrator', 'role-workspace', 'user'],
        'saved-views' => ['administrator', 'role-workspace', 'user'],
        'layout' => ['site', 'administrator'],
        'theme-mode' => ['site', 'user'],
        'dashboard-cards' => ['administrator', 'role-workspace', 'user'],
        'landing-workspace' => ['administrator', 'role-workspace', 'user'],
        'navigation-shortcuts' => ['role-workspace', 'user'],
        'labels-help' => ['administrator'],
    ];

    /**
     * Determine whether one requested layer sits at or below a declaration's legal scope ceiling.
     *
     * The scope sequence is slot-specific: unsupported layers are never introduced merely because they
     * appear earlier in the global customization hierarchy. Area-specific admission remains the live
     * surface policy's responsibility because this validator evaluates portable declarations in isolation.
     *
     * @param   CustomizationSlot   $slot       Safe presentation choice exposed by the surface.
     * @param   CustomizationScope  $ceiling    Highest layer named by the portable surface declaration.
     * @param   CustomizationScope  $requested  Exact lower or equal layer considered by the runtime.
     *
     * @return  bool  True only when both scopes are legal for the slot and the request does not exceed the ceiling.
     *
     * @since   2.0.0
     */
    public static function allowsCustomizationAtOrBelow(
        CustomizationSlot $slot,
        CustomizationScope $ceiling,
        CustomizationScope $requested,
    ): bool {
        $scopes = self::CUSTOMIZATION_SCOPES[$slot->value] ?? [];
        $ceilingIndex = array_search($ceiling->value, $scopes, true);
        $requestedIndex = array_search($requested->value, $scopes, true);

        return is_int($ceilingIndex)
            && is_int($requestedIndex)
            && $requestedIndex <= $ceilingIndex;
    }

    /**
     * Evaluate every cross-field KIS 1.0 invariant without stopping at the first failure.
     *
     * @param   SurfaceDeclaration  $declaration  Locally safe typed semantic candidate.
     *
     * @return  SurfaceConformanceReport  Complete deterministic admission evidence.
     *
     * @since   2.0.0
     */
    public function validate(SurfaceDeclaration $declaration): SurfaceConformanceReport
    {
        $diagnostics = [];
        $this->validateAreaActor($declaration, $diagnostics);
        $this->validatePattern($declaration, $diagnostics);
        $this->validateCapabilitiesAndStates($declaration, $diagnostics);
        $this->validateCustomization($declaration, $diagnostics);
        $this->validateResponsiveBehavior($declaration, $diagnostics);

        return new SurfaceConformanceReport($diagnostics);
    }

    /**
     * Reject a candidate with its complete diagnostic report.
     *
     * @param   SurfaceDeclaration  $declaration  Candidate to admit into the contribution architecture.
     *
     * @return  void
     *
     * @throws  SurfaceConformanceViolation  When one or more error diagnostics are present.
     *
     * @since   2.0.0
     */
    public function assertConforms(SurfaceDeclaration $declaration): void
    {
        $report = $this->validate($declaration);
        if (!$report->conforms()) {
            throw new SurfaceConformanceViolation($report);
        }
    }

    /**
     * Enforce separation between the human actor and the shell or template delivery area.
     *
     * @param   SurfaceDeclaration           $declaration  Candidate under evaluation.
     * @param   list<ConformanceDiagnostic>  $diagnostics  Accumulated findings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateAreaActor(SurfaceDeclaration $declaration, array &$diagnostics): void
    {
        $invalid = match ($declaration->area) {
            SurfaceArea::Administrator => $declaration->actor === SurfaceActor::Portal,
            SurfaceArea::Portal => $declaration->actor === SurfaceActor::Administrator,
            SurfaceArea::Public => $declaration->actor !== SurfaceActor::Public,
            SurfaceArea::Template => false,
        };
        if ($invalid) {
            $diagnostics[] = self::error(
                'kis.actor.area',
                'actor',
                'The intended actor is incompatible with the declared surface area.',
            );
        }
    }

    /**
     * Require the selected layout composition to implement the declared semantic task.
     *
     * @param   SurfaceDeclaration           $declaration  Candidate under evaluation.
     * @param   list<ConformanceDiagnostic>  $diagnostics  Accumulated findings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validatePattern(SurfaceDeclaration $declaration, array &$diagnostics): void
    {
        $patterns = self::INTENT_PATTERNS[$declaration->intent->value] ?? [];
        if (
            !in_array(
                $declaration->pattern->value,
                $patterns,
                true,
            )
        ) {
            $diagnostics[] = self::error(
                'kis.pattern.intent',
                'pattern',
                'The selected KIS pattern does not implement the declared semantic intent.',
            );
        }
    }

    /**
     * Enforce policy-aware actor declarations and the state evidence required by each task.
     *
     * @param   SurfaceDeclaration           $declaration  Candidate under evaluation.
     * @param   list<ConformanceDiagnostic>  $diagnostics  Accumulated findings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateCapabilitiesAndStates(SurfaceDeclaration $declaration, array &$diagnostics): void
    {
        $states = array_map(static fn (SurfaceState $state): string => $state->value, $declaration->states);
        if (!in_array(SurfaceState::Default->value, $states, true)) {
            $diagnostics[] = self::error(
                'kis.state.default-required',
                'states',
                'Every KIS surface must declare its representative default state.',
            );
        }
        if ($declaration->actor !== SurfaceActor::Public && $declaration->capabilities === []) {
            $diagnostics[] = self::error(
                'kis.capability.required',
                'capabilities',
                'An authenticated actor surface must declare at least one capability requirement.',
            );
        }
        if ($declaration->actor === SurfaceActor::Public && $declaration->capabilities !== []) {
            $diagnostics[] = self::error(
                'kis.capability.public',
                'capabilities',
                'A public actor surface cannot depend on an authenticated capability.',
            );
        }
        if (
            $declaration->capabilities !== []
            && !in_array(SurfaceState::PermissionReduced->value, $states, true)
        ) {
            $diagnostics[] = self::error(
                'kis.state.permission-reduced-required',
                'states',
                'A capability-filtered surface must cover its permission-reduced state.',
            );
        }

        $required = match ($declaration->intent) {
            SurfaceIntent::Collection, SurfaceIntent::ParentChild => [
                SurfaceState::Empty,
                SurfaceState::Dense,
                SurfaceState::Error,
            ],
            SurfaceIntent::Form, SurfaceIntent::Workflow, SurfaceIntent::Settings => [SurfaceState::Error],
            default => [],
        };
        foreach ($required as $state) {
            if (!in_array($state->value, $states, true)) {
                $diagnostics[] = self::error(
                    'kis.state.intent-required',
                    'states',
                    sprintf('The %s intent must cover its %s state.', $declaration->intent->value, $state->value),
                );
            }
        }
    }

    /**
     * Refuse customization at a configuration layer the approved slot does not support.
     *
     * @param   SurfaceDeclaration           $declaration  Candidate under evaluation.
     * @param   list<ConformanceDiagnostic>  $diagnostics  Accumulated findings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateCustomization(SurfaceDeclaration $declaration, array &$diagnostics): void
    {
        foreach ($declaration->customization as $index => $permission) {
            $scopes = self::CUSTOMIZATION_SCOPES[$permission->slot->value] ?? [];
            if (
                !in_array(
                    $permission->scope->value,
                    $scopes,
                    true,
                )
            ) {
                $diagnostics[] = self::error(
                    'kis.customization.scope',
                    sprintf('customization[%d].scope', $index),
                    sprintf(
                        'Customization slot %s cannot be changed at scope %s.',
                        $permission->slot->value,
                        $permission->scope->value,
                    ),
                );
            }
        }
    }

    /**
     * Require explicit constrained-container behavior and preserve essential information directly.
     *
     * @param   SurfaceDeclaration           $declaration  Candidate under evaluation.
     * @param   list<ConformanceDiagnostic>  $diagnostics  Accumulated findings.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function validateResponsiveBehavior(SurfaceDeclaration $declaration, array &$diagnostics): void
    {
        if ($declaration->responsive === []) {
            $diagnostics[] = self::error(
                'kis.responsive.required',
                'responsive',
                'Every KIS surface must declare at least one responsive semantic priority.',
            );
            return;
        }

        foreach ($declaration->responsive as $index => $element) {
            if ($element->priority === ResponsivePriority::Essential && $element->mayCollapse) {
                $diagnostics[] = self::error(
                    'kis.responsive.essential-collapse',
                    sprintf('responsive[%d].may_collapse', $index),
                    'An essential responsive element cannot collapse into secondary detail.',
                );
            }
        }
    }

    /**
     * Build one admission-blocking diagnostic with the canonical severity.
     *
     * @param   string  $code     Stable dotted diagnostic code.
     * @param   string  $path     Declaration field containing the failure.
     * @param   string  $message  Complete correction-oriented explanation.
     *
     * @return  ConformanceDiagnostic  Structured error finding.
     *
     * @since   2.0.0
     */
    private static function error(string $code, string $path, string $message): ConformanceDiagnostic
    {
        return new ConformanceDiagnostic($code, ConformanceSeverity::Error, $path, $message);
    }
}
