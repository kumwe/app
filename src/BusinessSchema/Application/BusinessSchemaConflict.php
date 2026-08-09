<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

use RuntimeException;

/**
 * Signals that schema work met a state the plan it was approved against no longer describes.
 *
 * A schema plan is composed, approved, and only later executed, and execution runs for as long as the
 * DDL takes while ordinary administration continues around it. Every stage therefore re-reads what it
 * depends on instead of trusting what planning saw, and this is the single refusal all of those checks
 * raise: the plan or the published definition moved after approval, the installed physical schema drifted
 * from its persisted map, an operation did not satisfy its approved postcondition, recovery evidence does
 * not match this engine and release, or a locked row changed underneath the run. Persistence and schema
 * adapters translate their driver failures into it as well, so the executor sees one conflict type rather
 * than Doctrine exceptions. `BusinessApiResponder` renders it as `409 Conflict` under
 * `urn:kumwe:problem:business-schema-conflict`, which states the remedy exactly: re-read the current
 * state and plan again, never resubmit the same request.
 *
 * @since  2.0.0
 */
final class BusinessSchemaConflict extends RuntimeException
{
}
