<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

/**
 * Responsive treatment of one semantic field, action, status, or metadata group.
 *
 * @since  2.0.0
 */
final readonly class ResponsiveElement
{
    /**
     * Declare importance and whether constrained layouts may move the element into secondary detail.
     *
     * @param  ResourceName        $element      Semantic element name, never a CSS selector.
     * @param  ResponsivePriority  $priority     Importance when the usable container narrows.
     * @param  bool                $mayCollapse  Whether a labelled disclosure may replace direct display.
     *
     * @since  2.0.0
     */
    public function __construct(
        public ResourceName $element,
        public ResponsivePriority $priority,
        public bool $mayCollapse,
    ) {
    }

    /**
     * Export deterministic responsive metadata for a manifest or conformance inventory.
     *
     * @return  array{element: string, priority: string, may_collapse: bool}  Semantic responsive declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'element' => $this->element->value(),
            'priority' => $this->priority->value,
            'may_collapse' => $this->mayCollapse,
        ];
    }
}
