<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

/**
 * Approved KIS interaction composition selected for a semantic intent.
 *
 * These values name behavior and information architecture, not Twig files or CSS classes. Renderers
 * remain replaceable while preserving the declared interaction contract.
 *
 * @since  2.0.0
 */
enum SurfacePattern: string
{
    /**
     * Search, filters, result collection, pagination, and one primary create action.
     *
     * @since  2.0.0
     */
    case CollectionWorkspace = 'collection-workspace';

    /**
     * Searchable catalog beside a focused selected-resource workspace.
     *
     * @since  2.0.0
     */
    case MasterDetailWorkspace = 'master-detail-workspace';

    /**
     * One bounded form presented as the primary page task.
     *
     * @since  2.0.0
     */
    case FocusedForm = 'focused-form';

    /**
     * Bounded form presented in a focus-managed detail drawer.
     *
     * @since  2.0.0
     */
    case DrawerForm = 'drawer-form';

    /**
     * URL- or state-addressable ordered steps ending in a review boundary.
     *
     * @since  2.0.0
     */
    case StepFlow = 'step-flow';

    /**
     * Small bounded child collection edited within its parent transaction.
     *
     * @since  2.0.0
     */
    case InlineSubform = 'inline-subform';

    /**
     * Independently searchable child collection with a focused child editor.
     *
     * @since  2.0.0
     */
    case ChildCollection = 'child-collection';

    /**
     * URL-addressable horizontal concerns with complete keyboard semantics.
     *
     * @since  2.0.0
     */
    case Tabs = 'tabs';

    /**
     * Stable grouped workspace destinations used when horizontal tabs would overload.
     *
     * @since  2.0.0
     */
    case LocalNavigation = 'local-navigation';

    /**
     * Policy-filtered search and selected-resource summaries for relationship choices.
     *
     * @since  2.0.0
     */
    case ResourceChooser = 'resource-chooser';

    /**
     * Dedicated high-impact review stating target, scope, version, and consequence.
     *
     * @since  2.0.0
     */
    case ReviewConfirmation = 'review-confirmation';

    /**
     * Structured semantic difference or before-and-after presentation.
     *
     * @since  2.0.0
     */
    case Comparison = 'comparison';

    /**
     * Progress, last update, retry, and recovery presentation for long-running work.
     *
     * @since  2.0.0
     */
    case StatusWorkspace = 'status-workspace';

    /**
     * Grouped configuration with explicit dirty, save, validation, and conflict states.
     *
     * @since  2.0.0
     */
    case SettingsWorkspace = 'settings-workspace';

    /**
     * Progressive technical evidence kept separate from ordinary operational work.
     *
     * @since  2.0.0
     */
    case DiagnosticsWorkspace = 'diagnostics-workspace';
}
