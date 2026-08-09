<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain\StepUp;

use InvalidArgumentException;

/**
 * Successful enrollment result containing recovery codes that can never be retrieved again.
 *
 * @since  2.0.0
 */
final readonly class StepUpEnrollmentCompletion
{
    /**
     * Carry the fresh proof and one-time recovery-code display.
     *
     * @param  StepUpVerification  $verification   Context-bound enrollment confirmation proof.
     * @param  list<string>        $recoveryCodes  High-entropy codes shown exactly once.
     *
     * @throws InvalidArgumentException  When the result is not a TOTP confirmation or codes are malformed.
     *
     * @since  2.0.0
     */
    public function __construct(
        public StepUpVerification $verification,
        public array $recoveryCodes,
    ) {
        if ($verification->method !== StepUpMethod::Totp || !array_is_list($recoveryCodes)) {
            throw new InvalidArgumentException('A step-up enrollment completion is invalid.');
        }
        $unique = [];
        foreach ($recoveryCodes as $code) {
            if (!is_string($code) || preg_match('/^(?:[0-9a-f]{4}-){7}[0-9a-f]{4}$/D', $code) !== 1) {
                throw new InvalidArgumentException('A step-up enrollment recovery code is invalid.');
            }
            $unique[$code] = true;
        }
        if (count($recoveryCodes) !== 10 || count($unique) !== 10) {
            throw new InvalidArgumentException('A step-up enrollment must contain ten unique recovery codes.');
        }
    }
}
