<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use InvalidArgumentException;

/**
 * One authorized host resource projected into Studio's portable search contract.
 *
 * @since  2.0.0
 */
final readonly class StudioResourceSearchItem
{
    /**
     * Retain only a stable identifier and a bounded human-readable label.
     *
     * @param  string  $id     Stable resource identifier understood by the App host.
     * @param  string  $label  Human-readable label already filtered through App policy.
     *
     * @since  2.0.0
     */
    public function __construct(public string $id, public string $label)
    {
        if (
            $id === ''
            || strlen($id) > 240
            || in_array($id, ['__proto__', 'prototype', 'constructor'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,239}$/D', $id) !== 1
        ) {
            throw new InvalidArgumentException('A Studio resource search item ID is invalid.');
        }
        if ($label === '' || mb_strlen($label) > 500) {
            throw new InvalidArgumentException('A Studio resource search item label is invalid.');
        }
    }
}
