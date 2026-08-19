<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Infrastructure\Persistence;

use InvalidArgumentException;

/**
 * One metadata-validated record-policy SQL predicate and its ordered bindings.
 *
 * @since  2.0.0
 */
final readonly class CompiledRecordPredicate
{
    /**
     * Placeholder values in the same order as the compiled SQL expression.
     *
     * @var    list<mixed>
     * @since  2.0.0
     */
    public array $parameters;

    /**
     * Doctrine binding types corresponding one-for-one with the parameter values.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $types;

    /**
     * Create one validated SQL predicate and its ordered bindings.
     *
     * @param   string        $sql         Definite-boolean SQL predicate using only validated identifiers.
     * @param   list<mixed>   $parameters  Values for placeholders in predicate order.
     * @param   list<string>  $types       Doctrine type name for every parameter.
     *
     * @throws  InvalidArgumentException  When parameter and type counts differ.
     *
     * @since   2.0.0
     */
    public function __construct(public string $sql, array $parameters, array $types)
    {
        if (count($parameters) !== count($types)) {
            throw new InvalidArgumentException('A compiled record-policy predicate has mismatched bindings.');
        }
        $this->parameters = array_values($parameters);
        $this->types = array_values($types);
    }
}
