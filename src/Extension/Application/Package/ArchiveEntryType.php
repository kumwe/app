<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

/**
 * What a single entry in an extension archive is, as classified from the archive directory.
 *
 * `ZipArchiveReader` decides the case from the entry name and the Unix mode in the ZIP external
 * attributes, before anything is expanded. `PackageSafetyPolicy` then reads it to make two decisions it
 * could not make from a path and a size alone: a link is refused outright, and only a regular file at
 * the archive root can satisfy the required `kumwe.json` manifest.
 *
 * @since  2.0.0
 */
enum ArchiveEntryType: string
{
    /**
     * A regular file carrying payload bytes, and the only kind that can serve as the package manifest.
     *
     * @since  2.0.0
     */
    case File = 'file';
    /**
     * A path entry with no payload of its own, so both of its recorded sizes are zero.
     *
     * @since  2.0.0
     */
    case Directory = 'directory';
    /**
     * An entry whose mode marks it a symbolic link, which forces the whole package to be rejected.
     *
     * A link inside a package is a way to redirect a later write outside the deployment, or to smuggle a
     * reference to a host file into the extension tree, so no packaging need justifies allowing one.
     *
     * @since  2.0.0
     */
    case SymbolicLink = 'symbolic_link';
}
