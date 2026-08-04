<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use Kumwe\CMS\Presentation\Domain\ModuleAssignment;
use Kumwe\CMS\Presentation\Domain\TemplateDefinition;

final readonly class RenderPlan
{
    /** @param array<string, list<ModuleAssignment>> $modulesBySlot */
    public function __construct(
        private TemplateDefinition $template,
        private array $modulesBySlot,
    ) {
    }

    public function template(): TemplateDefinition
    {
        return $this->template;
    }

    /** @return array<string, list<ModuleAssignment>> */
    public function modulesBySlot(): array
    {
        return $this->modulesBySlot;
    }
}
