<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;

/**
 * Policy-filtered owned-line target definition and create-field handles.
 *
 * @since  2.0.0
 */
final readonly class OwnedLineFormResult
{
    /**
     * Validate the field subset before it reaches semantic presentation.
     *
     * @param   EntityTypeDefinition  $definition   Active owned-line target definition.
     * @param   list<string>          $fieldHandles Create fields granted by the nested relation plan.
     *
     * @throws  InvalidArgumentException  When handles are malformed, repeated, or unbounded.
     *
     * @since   2.0.0
     */
    public function __construct(public EntityTypeDefinition $definition, public array $fieldHandles)
    {
        if (count($fieldHandles) > 256 || count($fieldHandles) !== count(array_unique($fieldHandles))) {
            throw new InvalidArgumentException('Owned-line form fields are duplicated or unbounded.');
        }
        foreach ($fieldHandles as $handle) {
            if (!is_string($handle) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('An owned-line form field handle is invalid.');
            }
        }
    }
}
