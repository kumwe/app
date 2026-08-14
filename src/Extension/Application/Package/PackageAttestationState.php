<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

/**
 * What an installation was able to establish about a package's bill of materials or provenance.
 *
 * There are deliberately only two states that survive an install. Either the document was present and
 * every claim an installation can check was checked and held, or the package carried no such document
 * at all. A document that is present and wrong is not a third state: it refuses the install, because
 * the attestation documents travel inside the package bytes the signature covers, so a mismatch means
 * either a builder that is not the one it claims to be or bytes that changed without the signature
 * noticing. `Absent` exists because packages built before attestations shipped must keep installing.
 *
 * @since  2.0.0
 */
enum PackageAttestationState: string
{
    /**
     * The document was present and agreed with the package bytes it describes.
     *
     * @since  2.0.0
     */
    case Verified = 'verified';

    /**
     * The package carried no such document, so nothing was claimed and nothing was checked.
     *
     * @since  2.0.0
     */
    case Absent = 'absent';
}
