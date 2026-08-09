<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

/**
 * One other package an extension requires, and the versions of it that will do.
 *
 * `ExtensionManifest` keeps these in declaration order after rejecting self-references and repeats, so
 * a dependency here names a package other than the one declaring it, at most once. Installation reads
 * the list twice: once to write the release's `extension_dependencies` rows, and once to refuse a
 * package whose requirements are absent or out of range. `optional` only excuses absence — a package
 * that is installed still has to satisfy the constraint.
 *
 * @since  2.0.0
 */
final readonly class ExtensionDependency
{
    /**
     * Capture one requirement exactly as the manifest declared it.
     *
     * @param  ExtensionIdentifier  $extension   Package that must be installed for the declaring one to run.
     * @param  VersionConstraint    $constraint  Range of that package's versions this declaration accepts.
     * @param  bool                 $optional    True when the requirement may be skipped if nothing provides it.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionIdentifier $extension,
        private VersionConstraint $constraint,
        private bool $optional = false,
    ) {
    }

    /**
     * The package this requirement points at.
     *
     * @return  ExtensionIdentifier  Identifier of the required package, never the declaring package itself.
     *
     * @since   2.0.0
     */
    public function extension(): ExtensionIdentifier
    {
        return $this->extension;
    }

    /**
     * The version range the required package has to fall in.
     *
     * @return  VersionConstraint  Constraint as declared; `*` when the manifest named no range.
     *
     * @since   2.0.0
     */
    public function constraint(): VersionConstraint
    {
        return $this->constraint;
    }

    /**
     * Whether an installation with nothing providing this package is still allowed to proceed.
     *
     * @return  bool  True when the requirement is skipped while absent, false when absence blocks install.
     *
     * @since   2.0.0
     */
    public function isOptional(): bool
    {
        return $this->optional;
    }

    /**
     * Decide whether an installed version of the required package meets this requirement.
     *
     * Only the version is judged. Whether the package is present at all, and whether `optional` excuses
     * its absence, is the caller's question — this method is never reached for a missing dependency.
     *
     * @param   SemanticVersion  $version  Version of the required package that is actually installed.
     *
     * @return  bool  True when that version falls inside the declared constraint.
     *
     * @since   2.0.0
     */
    public function isSatisfiedBy(SemanticVersion $version): bool
    {
        return $this->constraint->accepts($version);
    }
}
