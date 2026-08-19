<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

/**
 * User task a surface exists to support before any visual layout is selected.
 *
 * @since  2.0.0
 */
enum SurfaceIntent: string
{
    /**
     * Find and browse a resource collection.
     *
     * @since  2.0.0
     */
    case Collection = 'collection';

    /**
     * Inspect one resource and its stable concerns.
     *
     * @since  2.0.0
     */
    case Detail = 'detail';

    /**
     * Create or edit one resource through validated fields.
     *
     * @since  2.0.0
     */
    case Form = 'form';

    /**
     * Manage a parent together with its bounded child collection.
     *
     * @since  2.0.0
     */
    case ParentChild = 'parent-child';

    /**
     * Search for and select one or more related resources.
     *
     * @since  2.0.0
     */
    case Chooser = 'chooser';

    /**
     * Progress a resource through an ordered business workflow.
     *
     * @since  2.0.0
     */
    case Workflow = 'workflow';

    /**
     * Review and confirm a high-impact or externally visible operation.
     *
     * @since  2.0.0
     */
    case Review = 'review';

    /**
     * Compare versions or meaningful before-and-after state.
     *
     * @since  2.0.0
     */
    case Comparison = 'comparison';

    /**
     * Monitor execution, progress, retry, or recovery state.
     *
     * @since  2.0.0
     */
    case Monitor = 'monitor';

    /**
     * Configure a singleton, installation, workspace, or other settings resource.
     *
     * @since  2.0.0
     */
    case Settings = 'settings';

    /**
     * Explore technical diagnostics without making them the ordinary workflow.
     *
     * @since  2.0.0
     */
    case Diagnostics = 'diagnostics';
}
