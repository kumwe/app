<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Kumwe\App\Identity\Application\StepUp\StepUpProvider;
use Kumwe\App\Identity\Domain\StepUp\RotatedStepUpSession;
use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentCompletion;
use Kumwe\App\Identity\Domain\StepUp\StepUpEnrollmentSetup;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpMethod;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;

/**
 * Captures generated-action provider inputs and returns one deterministic rotated proof.
 *
 * @since  2.0.0
 */
final class GeneratedActionCapturingStepUpProvider implements StepUpProvider
{
    /**
     * Last server-owned challenge intent.
     *
     * @var    StepUpIntent|null
     * @since  2.0.0
     */
    public ?StepUpIntent $lastIntent = null;

    /**
     * Last submitted credential.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $credential = '';

    /**
     * Last trusted attempt source.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $source = '';

    /**
     * Enrollment is outside this focused verifier double.
     *
     * @param   string  $subjectId     Actor identity.
     * @param   string  $issuer        Authenticator issuer.
     * @param   string  $accountLabel  Authenticator account label.
     *
     * @return  StepUpEnrollmentSetup  Never returned.
     *
     * @since   2.0.0
     */
    public function beginEnrollment(string $subjectId, string $issuer, string $accountLabel): StepUpEnrollmentSetup
    {
        throw new \LogicException('Enrollment is outside the generated action verifier test.');
    }

    /**
     * Enrollment confirmation is outside this focused verifier double.
     *
     * @param   StepUpIntent  $intent        Enrollment intent.
     * @param   string        $enrollmentId  Pending enrollment identity.
     * @param   string        $code          Authenticator code.
     * @param   string        $source        Trusted source.
     *
     * @return  StepUpEnrollmentCompletion  Never returned.
     *
     * @since   2.0.0
     */
    public function confirmEnrollment(
        StepUpIntent $intent,
        string $enrollmentId,
        string $code,
        string $source,
    ): StepUpEnrollmentCompletion {
        throw new \LogicException('Enrollment is outside the generated action verifier test.');
    }

    /**
     * Capture one authenticator challenge.
     *
     * @param   StepUpIntent  $intent  Exact challenge intent.
     * @param   string        $code    Submitted authenticator code.
     * @param   string        $source  Trusted source.
     *
     * @return  StepUpVerification  Deterministic rotated proof.
     *
     * @since   2.0.0
     */
    public function challenge(StepUpIntent $intent, string $code, string $source): StepUpVerification
    {
        return $this->verification($intent, $code, $source, StepUpMethod::Totp);
    }

    /**
     * Capture one recovery-code challenge.
     *
     * @param   StepUpIntent  $intent        Exact challenge intent.
     * @param   string        $recoveryCode  Submitted recovery code.
     * @param   string        $source        Trusted source.
     *
     * @return  StepUpVerification  Deterministic rotated proof.
     *
     * @since   2.0.0
     */
    public function recover(StepUpIntent $intent, string $recoveryCode, string $source): StepUpVerification
    {
        return $this->verification($intent, $recoveryCode, $source, StepUpMethod::RecoveryCode);
    }

    /**
     * Record inputs and build one internally consistent provider result.
     *
     * @param   StepUpIntent  $intent      Exact challenge intent.
     * @param   string        $credential  Submitted credential.
     * @param   string        $source      Trusted attempt source.
     * @param   StepUpMethod  $method      Successful verification method.
     *
     * @return  StepUpVerification  Fresh proof and rotated session.
     *
     * @since   2.0.0
     */
    private function verification(
        StepUpIntent $intent,
        string $credential,
        string $source,
        StepUpMethod $method,
    ): StepUpVerification {
        $this->lastIntent = $intent;
        $this->credential = $credential;
        $this->source = $source;
        $now = new DateTimeImmutable('2026-08-10T10:00:00+00:00');

        return new StepUpVerification(
            $intent,
            '018f0000-0000-7000-8000-000000000011',
            $method,
            $now,
            $now->modify('+5 minutes'),
            str_repeat('n', 43),
            new RotatedStepUpSession(
                '018f0000-0000-7000-8000-000000000012',
                'rotated_cookie_token_' . str_repeat('x', 32),
                str_repeat('z', 43),
                $now->modify('+1 hour'),
            ),
        );
    }
}
