<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

/**
 * Whitelisted presentation choice a conforming surface may expose for customization.
 *
 * Security meaning, authorization visibility, warnings, audit context, destructive classification,
 * and accessibility semantics are intentionally absent and therefore cannot be customized away.
 *
 * @since  2.0.0
 */
enum CustomizationSlot: string
{
    /**
     * Visible resource-list columns and their presentation order.
     *
     * @since  2.0.0
     */
    case Columns = 'columns';

    /**
     * Comfortable, compact, or touch-oriented spacing density.
     *
     * @since  2.0.0
     */
    case Density = 'density';

    /**
     * Named filters, sorting, and bounded pagination preferences.
     *
     * @since  2.0.0
     */
    case SavedViews = 'saved-views';

    /**
     * Approved KIS layout variant without arbitrary markup or style injection.
     *
     * @since  2.0.0
     */
    case Layout = 'layout';

    /**
     * Light, dark, or system theme mode.
     *
     * @since  2.0.0
     */
    case ThemeMode = 'theme-mode';

    /**
     * Selection and ordering of approved dashboard cards.
     *
     * @since  2.0.0
     */
    case DashboardCards = 'dashboard-cards';

    /**
     * Authorized default workspace shown after entry.
     *
     * @since  2.0.0
     */
    case LandingWorkspace = 'landing-workspace';

    /**
     * Authorized destinations pinned into navigation.
     *
     * @since  2.0.0
     */
    case NavigationShortcuts = 'navigation-shortcuts';

    /**
     * Localized labels and help text resolved through the translation override boundary.
     *
     * @since  2.0.0
     */
    case LabelsHelp = 'labels-help';
}
