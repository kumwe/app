<?php

/**
 * Retains the internal extension refusal name after ownership moved to the Studio contract domain.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain\Internal\StudioProfile;

class_alias(\Kumwe\App\Studio\Domain\Contract\SchemaProfileRejected::class, __NAMESPACE__ . '\\SchemaProfileRejected');
