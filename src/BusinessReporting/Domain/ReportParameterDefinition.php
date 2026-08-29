<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use InvalidArgumentException;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportDefinitionGuard;
use Kumwe\Extension\Spi\BusinessReporting\Domain\ReportValueType;

/**
 * One named and typed value a report caller may bind.
 *
 * @since  2.0.0
 */
final readonly class ReportParameterDefinition
{
    /**
     * Declare one parameter and validate its optional default immediately.
     *
     * @param   string           $name          Lowercase handle referenced by filters.
     * @param   ReportValueType  $type          Scalar type every supplied value must have.
     * @param   bool             $required      Whether the caller must supply the parameter.
     * @param   bool             $multiple      Whether the parameter accepts one to one hundred values.
     * @param   mixed            $defaultValue  Default used when the caller omits the parameter.
     *
     * @throws  InvalidArgumentException  When the handle or default is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $name,
        public ReportValueType $type,
        public bool $required = false,
        public bool $multiple = false,
        public mixed $defaultValue = null,
    ) {
        ReportDefinitionGuard::handle($name, 'parameter');
        if ($required && $defaultValue !== null) {
            throw new InvalidArgumentException('A required report parameter cannot declare a default.');
        }
        if ($defaultValue !== null) {
            $this->assertValue($defaultValue);
        }
    }

    /**
     * Validate and return a caller-supplied value without coercion.
     *
     * @param   mixed  $value  Value to validate.
     *
     * @return  mixed  The unchanged value after it has satisfied this definition.
     *
     * @throws  InvalidArgumentException  When the value is absent, has the wrong type, or exceeds a list bound.
     *
     * @since   2.0.0
     */
    public function assertValue(mixed $value): mixed
    {
        if ($value === null) {
            if ($this->required) {
                throw new InvalidArgumentException(sprintf('Report parameter "%s" is required.', $this->name));
            }

            return null;
        }
        $values = $this->multiple ? $value : [$value];
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > 100) {
            throw new InvalidArgumentException(sprintf(
                'Report parameter "%s" has an invalid value list.',
                $this->name,
            ));
        }
        foreach ($values as $item) {
            if (!$this->type->accepts($item)) {
                throw new InvalidArgumentException(sprintf('Report parameter "%s" has the wrong type.', $this->name));
            }
        }

        return $value;
    }
}
