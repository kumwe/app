<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use InvalidArgumentException;

/**
 * Signals that a business-schema document breaks a rule this namespace enforces.
 *
 * Blueprints, plans, steps, and recovery evidence all validate themselves as they are constructed and
 * are all rebuilt from untrusted arrays through `SchemaDocument`, so the whole namespace raises this
 * one type: an importer, a repository hydrating a row, or a delivery adapter has a single class to
 * catch whichever field, identifier, checksum, or portability rule was broken. It extends
 * `InvalidArgumentException` because a rejected document is bad input rather than a broken
 * installation, which is what lets `BusinessApiResponder` answer it with 422 instead of a fault.
 * Messages name the rule that failed and stay operator-facing.
 *
 * @since  2.0.0
 */
final class InvalidBusinessSchema extends InvalidArgumentException
{
}
