<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Delivery\Browser;

/**
 * Retains safe generated-action controls after a rejected step-up attempt.
 *
 * Second-factor credentials and browser security tokens are single-attempt inputs. They never enter a
 * confirmation query or Twig model; declared action input and approval bindings remain available so the
 * actor can correct only the failed verification control without rebuilding the reviewed action.
 *
 * @since  2.0.0
 */
final readonly class GeneratedBusinessConfirmationQuery
{
    /**
     * Remove one-attempt secrets before rebuilding the action confirmation model.
     *
     * @param   array<string, mixed>  $body  Submitted action and verification controls.
     *
     * @return  array<string, mixed>  Safe retained action query with confirmation selected.
     *
     * @since   2.0.0
     */
    public static function retain(array $body): array
    {
        unset(
            $body['_csrf'],
            $body['confirmed'],
            $body['verification'],
            $body['verification_method'],
        );
        $body['confirm'] = 'action';

        return $body;
    }
}
