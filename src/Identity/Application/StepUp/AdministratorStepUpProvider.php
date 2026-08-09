<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Application\StepUp;

/**
 * Administrator-scoped provider instance whose session rotator targets only administrator sessions.
 *
 * The marker prevents the composition root from accidentally injecting the portal provider into an
 * administrator flow even though both expose the same enrollment and challenge operations.
 *
 * @since  2.0.0
 */
interface AdministratorStepUpProvider extends StepUpProvider
{
}
