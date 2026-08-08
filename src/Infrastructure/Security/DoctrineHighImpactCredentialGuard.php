<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Security;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Security\HighImpactAuthenticationRequired;
use Kumwe\CMS\Application\Security\HighImpactCredentialGuard;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationRateLimiter;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

final readonly class DoctrineHighImpactCredentialGuard implements HighImpactCredentialGuard
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private PasswordHasher $passwords,
        private AuthenticationRateLimiter $rateLimiter,
    ) {
    }

    public function assertCurrentPassword(
        ExecutionContext $context,
        string $purpose,
        #[\SensitiveParameter] ?string $credential,
    ): void {
        if (preg_match('/^[a-z][a-z0-9._-]{2,63}$/D', $purpose) !== 1) {
            throw new InvalidArgumentException('A high-impact authentication purpose is invalid.');
        }
        if ($context->principal() === null) {
            throw new HighImpactAuthenticationRequired(
                'This high-impact operation requires a human authentication context.',
            );
        }

        $actorId = $context->actorId();
        $subject = hash('sha256', 'high-impact:' . $purpose . ':' . $actorId);
        $source = hash('sha256', 'high-impact-current-password:' . $purpose);
        $this->rateLimiter->assertAllowed($subject, $source);
        $hash = $this->database->fetchOne(sprintf(
            'SELECT p.password_hash FROM %s p INNER JOIN %s u ON u.id = p.user_id '
            . "WHERE p.user_id = ? AND u.status = 'active'",
            $this->tables->quoted('password_credentials'),
            $this->tables->quoted('users'),
        ), [$actorId]);

        $verified = is_string($hash)
            && $credential !== null
            && $this->passwords->verify($credential, $hash);
        $this->rateLimiter->record($subject, $source, $verified);
        if (!$verified) {
            throw new HighImpactAuthenticationRequired(
                'This high-impact operation requires current-password authentication.',
            );
        }
    }
}
