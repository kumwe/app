<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Infrastructure;

use Doctrine\DBAL\Connection;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired;
use Kumwe\CMS\Presentation\Application\ThemeActivationGuard;
use Kumwe\CMS\Presentation\ThemeSurface;

final readonly class DoctrineThemeActivationGuard implements ThemeActivationGuard
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private PasswordHasher $passwords,
        private AuthenticationRateLimiter $rateLimiter,
    ) {
    }

    public function assertAllowed(
        ThemeSurface $surface,
        ExecutionContext $context,
        #[\SensitiveParameter] ?string $stepUpCredential,
    ): void {
        if ($surface === ThemeSurface::Site) {
            return;
        }

        $actorId = $context->actorId();
        if ($context->principal() === null) {
            throw new StepUpAuthenticationRequired(
                'Administrator theme activation requires a human step-up authentication context.',
            );
        }
        $subject = hash('sha256', 'administrator-theme:' . $actorId);
        $source = hash('sha256', 'administrator-theme-step-up');
        $this->rateLimiter->assertAllowed($subject, $source);
        $hash = $this->database->fetchOne(sprintf(
            'SELECT p.password_hash FROM %s p INNER JOIN %s u ON u.id = p.user_id '
            . "WHERE p.user_id = ? AND u.status = 'active'",
            $this->tables->quoted('password_credentials'),
            $this->tables->quoted('users'),
        ), [$actorId]);

        $verified = is_string($hash)
            && $stepUpCredential !== null
            && $this->passwords->verify($stepUpCredential, $hash);
        $this->rateLimiter->record($subject, $source, $verified);

        if (!$verified) {
            throw new StepUpAuthenticationRequired(
                'Administrator theme activation requires current-password step-up authentication.',
            );
        }
    }
}
