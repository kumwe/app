<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

/**
 * Safe strategy port for presenting one validated business field.
 *
 * Implementations receive no request, response, container, repository, database connection or template
 * name. Their only result is the bounded semantic model rendered by core-owned Twig.
 *
 * @since  2.0.0
 */
interface FieldPresenter
{
    /**
     * Present one policy-disclosed field value or retained submitted value.
     *
     * @param   FieldPresentationRequest  $request  Typed declarative presentation input.
     *
     * @return  FieldPresentation  Markup-free bounded semantic model.
     *
     * @since   2.0.0
     */
    public function present(FieldPresentationRequest $request): FieldPresentation;
}
