<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

use InvalidArgumentException;

/**
 * Signals that a submitted business definition breaks the definition contract.
 *
 * Every value object in this namespace validates itself as it is constructed and every one of them
 * raises this single type, so an assembler of definitions — the graphical form mapper, a definition
 * import, an extension contribution, the expression parser, or the runtime evaluator — has one class to
 * catch whatever rule was broken. It extends `InvalidArgumentException` because a rejected definition is
 * bad caller input rather than a failure of the installation, which is also what lets
 * `RecordRuleValidator` turn an unevaluatable invariant into a validation violation instead of a fault.
 * Messages name the rule that failed and stay operator-facing.
 *
 * @since  2.0.0
 */
final class InvalidBusinessDefinition extends InvalidArgumentException
{
}
