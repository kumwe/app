<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Host;

/**
 * The canonical flattened Studio authoring modes guarded by host policy.
 *
 * @since  2.0.0
 */
enum StudioSessionMode: string
{
    /**
     * Complete Blueprint structure authoring.
     *
     * @since  2.0.0
     */
    case Blueprint = 'blueprint';

    /**
     * Content field-value authoring.
     *
     * @since  2.0.0
     */
    case Content = 'content';

    /**
     * Content authoring with bounded structural regions.
     *
     * @since  2.0.0
     */
    case Hybrid = 'hybrid';

    /**
     * Content model field authoring.
     *
     * @since  2.0.0
     */
    case Model = 'model';

    /**
     * Inspection without mutation commands.
     *
     * @since  2.0.0
     */
    case ReadOnly = 'read-only';

    /**
     * Return the App capability that authorizes this exact mode.
     *
     * @return  string  Core capability identifier.
     *
     * @since   2.0.0
     */
    public function capability(): string
    {
        return 'studio.mode.' . $this->value;
    }
}
