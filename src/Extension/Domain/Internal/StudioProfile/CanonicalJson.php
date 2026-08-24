<?php

/**
 * Retains the internal extension namespace while canonical JSON is owned by the Studio contract domain.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain\Internal\StudioProfile;

class_alias(\Kumwe\App\Studio\Domain\Contract\CanonicalJson::class, __NAMESPACE__ . '\\CanonicalJson');
