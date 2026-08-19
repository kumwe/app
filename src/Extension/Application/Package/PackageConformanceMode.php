<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Application\Package;

/**
 * How strictly install-time admission treats the static conformance scan of packaged code.
 *
 * The scan itself always produces the same findings; this decides what an installation does with
 * them. `Enforce` is the shipped default and the only posture a production deployment may run under,
 * because a package whose PHP does not parse, or whose manifest names files it does not carry, is
 * broken in a way that only becomes visible after it has been unpacked and loaded. `Warn` exists for
 * an installation carrying legacy packages that have not been rebuilt yet: the same findings are
 * recorded and surfaced, but they do not refuse the install. `Off` skips the scan entirely and is
 * refused under production rules, so the mode cannot become the silent bypass the signature flag once
 * was.
 *
 * @since  2.0.0
 */
enum PackageConformanceMode: string
{
    /**
     * Run the scan and refuse an install carrying a blocking finding.
     *
     * @since  2.0.0
     */
    case Enforce = 'enforce';

    /**
     * Run the scan, record and surface every finding, and admit the package regardless.
     *
     * @since  2.0.0
     */
    case Warn = 'warn';

    /**
     * Skip the scan; the release records that no scan was taken rather than that it passed.
     *
     * @since  2.0.0
     */
    case Off = 'off';
}
