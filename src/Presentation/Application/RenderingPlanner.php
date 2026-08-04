<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use Kumwe\CMS\Presentation\Domain\ModuleAssignment;
use Kumwe\CMS\Presentation\Domain\PresentationContext;
use Kumwe\CMS\Presentation\Domain\TemplateAssignment;
use RuntimeException;

final class RenderingPlanner
{
    /**
     * @param list<TemplateAssignment> $templateAssignments
     * @param list<ModuleAssignment>   $moduleAssignments
     */
    public function plan(
        PresentationContext $context,
        array $templateAssignments,
        array $moduleAssignments,
    ): RenderPlan {
        $templates = array_values(array_filter(
            $templateAssignments,
            static fn (TemplateAssignment $assignment): bool => $assignment->matches($context),
        ));
        usort($templates, static fn (TemplateAssignment $left, TemplateAssignment $right): int => [
            -$left->priority(),
            $left->template()->id(),
            $left->id(),
        ] <=> [
            -$right->priority(),
            $right->template()->id(),
            $right->id(),
        ]);

        if ($templates === []) {
            throw new RuntimeException('No template assignment matches the presentation context.');
        }

        $template = $templates[0]->template();
        $modulesBySlot = [];

        foreach ($moduleAssignments as $assignment) {
            if (!$assignment->matches($context)) {
                continue;
            }

            if (!$template->hasSlot($assignment->slot())) {
                throw new RuntimeException(sprintf(
                    'Module assignment %s targets undeclared slot %s.',
                    $assignment->id(),
                    $assignment->slot(),
                ));
            }

            $modulesBySlot[$assignment->slot()][] = $assignment;
        }

        ksort($modulesBySlot, SORT_STRING);

        foreach ($modulesBySlot as &$assignments) {
            usort($assignments, static fn (ModuleAssignment $left, ModuleAssignment $right): int => [
                $left->position(),
                $left->moduleInstanceId(),
                $left->id(),
            ] <=> [
                $right->position(),
                $right->moduleInstanceId(),
                $right->id(),
            ]);
        }

        unset($assignments);

        return new RenderPlan($template, $modulesBySlot);
    }
}
