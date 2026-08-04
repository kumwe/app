<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Administration;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Application\Security\PasswordHasher;
use Kumwe\CMS\Identity\Domain\EmailAddress;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class PostgreSqlAdministratorIdentityGateway implements AdministratorIdentityGateway
{
    /** @var list<string> */
    private const ADMINISTRATOR_CAPABILITIES = [
        'administrator.access',
        'automation.manage',
        'content.create',
        'content.delete',
        'content.publish',
        'content.read',
        'content.update',
        'extensions.manage',
        'settings.manage',
        'users.manage',
    ];

    public function __construct(
        private DatabaseInterface $database,
        private string $schema,
        private PasswordHasher $passwords,
        private TransactionManager $transactions,
        private ClockInterface $clock,
        private string $applicationSecret,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
    }

    public function authenticate(string $email, string $password, string $source): ?AuthenticatedPrincipal
    {
        $normalized = EmailAddress::fromString($email)->value();
        $sourceDigest = hash_hmac('sha256', trim($source) === '' ? 'unknown' : $source, $this->applicationSecret);
        $subjectDigest = hash_hmac('sha256', $normalized, $this->applicationSecret);
        $threshold = $this->clock->now()->sub(new DateInterval('PT15M'))->format('Y-m-d H:i:s.uP');
        $countQuery = $this->database->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->quoteName($this->schema . '.authentication_attempts'))
            ->where($this->quoteName('subject_digest') . ' = :subject_digest')
            ->where($this->quoteName('source_digest') . ' = :source_digest')
            ->where($this->quoteName('succeeded') . ' = false')
            ->where($this->quoteName('occurred_at') . ' >= :threshold')
            ->bind(':subject_digest', $subjectDigest, ParameterType::STRING)
            ->bind(':source_digest', $sourceDigest, ParameterType::STRING)
            ->bind(':threshold', $threshold, ParameterType::STRING);

        if ((int) $this->database->setQuery($countQuery)->loadResult() >= 10) {
            throw new AuthenticationThrottled();
        }

        $query = $this->database->getQuery(true)
            ->select([
                $this->quoteName('u.id'),
                $this->quoteName('p.password_hash'),
            ])
            ->from($this->quoteName($this->schema . '.users', 'u'))
            ->join(
                'INNER',
                $this->quoteName($this->schema . '.password_credentials', 'p')
                    . ' ON ' . $this->quoteName('p.user_id') . ' = ' . $this->quoteName('u.id'),
            )
            ->where($this->quoteName('u.email_normalized') . ' = :email')
            ->where($this->quoteName('u.status') . " = 'active'")
            ->bind(':email', $normalized, ParameterType::STRING);
        $row = $this->database->setQuery($query)->loadAssoc();
        $valid = is_array($row)
            && is_string($row['password_hash'] ?? null)
            && $this->passwords->verify($password, $row['password_hash']);

        $this->recordAttempt($subjectDigest, $sourceDigest, $valid);

        if (!$valid || !is_string($row['id'] ?? null)) {
            return null;
        }

        return AuthenticatedPrincipal::fromStrings($row['id'], $this->capabilitiesFor($row['id']));
    }

    public function createInitialAdministrator(string $email, string $displayName, string $password): string
    {
        $address = EmailAddress::fromString($email);
        $displayName = trim($displayName);

        if ($displayName === '' || mb_strlen($displayName) > 191) {
            throw new InvalidArgumentException('The administrator display name must contain 1 to 191 characters.');
        }

        $userId = Uuid::uuid7()->toString();
        $roleId = Uuid::uuid7()->toString();
        $now = $this->clock->now()->format('Y-m-d H:i:s.uP');
        $passwordHash = $this->passwords->hash($password);

        return $this->transactions->transactional(function () use (
            $address,
            $displayName,
            $userId,
            $roleId,
            $now,
            $passwordHash,
        ): string {
            $this->assertInstallationHasNoUsers();
            $this->insertUser($userId, $address, $displayName, $now);
            $this->insertCredential($userId, $passwordHash, $now);
            $this->insertAdministratorRole($roleId, $userId, $now);

            return $userId;
        });
    }

    public function issueAccessToken(
        string $email,
        string $name,
        array $capabilities,
        ?DateTimeImmutable $expiresAt = null,
    ): array {
        $normalized = EmailAddress::fromString($email)->value();
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException('An access token name must contain 1 to 191 characters.');
        }

        if (!array_is_list($capabilities) || $capabilities === []) {
            throw new InvalidArgumentException('At least one access token capability is required.');
        }

        $query = $this->database->getQuery(true)
            ->select($this->quoteName('id'))
            ->from($this->quoteName($this->schema . '.users'))
            ->where($this->quoteName('email_normalized') . ' = :email')
            ->where($this->quoteName('status') . " = 'active'")
            ->bind(':email', $normalized, ParameterType::STRING);
        $userId = $this->database->setQuery($query)->loadResult();

        if (!is_string($userId)) {
            throw new InvalidArgumentException('The requested active user does not exist.');
        }

        $granted = $this->capabilitiesFor($userId);

        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !in_array($capability, $granted, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The user does not grant capability %s.',
                    (string) $capability,
                ));
            }
        }

        $token = $this->base64Url(random_bytes(48));
        $tokenId = Uuid::uuid7()->toString();
        $capabilityJson = json_encode(array_values(array_unique($capabilities)), JSON_THROW_ON_ERROR);
        $query = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.api_tokens'))
            ->columns($this->quoteNames([
                'id',
                'subject_id',
                'token_digest',
                'name',
                'capabilities',
                'expires_at',
                'created_at',
            ]))
            ->values(':id, :subject_id, :token_digest, :name, CAST(:capabilities AS jsonb), :expires_at, :created_at')
            ->bind(':id', $tokenId, ParameterType::STRING)
            ->bind(':subject_id', $userId, ParameterType::STRING)
            ->bind(':token_digest', hash('sha256', $token), ParameterType::STRING)
            ->bind(':name', $name, ParameterType::STRING)
            ->bind(':capabilities', $capabilityJson, ParameterType::STRING)
            ->bind(
                ':expires_at',
                $expiresAt?->format('Y-m-d H:i:s.uP'),
                $expiresAt === null ? ParameterType::NULL : ParameterType::STRING,
            )
            ->bind(':created_at', $this->clock->now()->format('Y-m-d H:i:s.uP'), ParameterType::STRING);
        $this->database->setQuery($query)->execute();

        return ['token' => $token, 'token_id' => $tokenId];
    }

    private function assertInstallationHasNoUsers(): void
    {
        $query = $this->database->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->quoteName($this->schema . '.users'));

        if ((int) $this->database->setQuery($query)->loadResult() !== 0) {
            throw new RuntimeException('The initial administrator can only be created before any user exists.');
        }
    }

    private function insertUser(string $id, EmailAddress $email, string $displayName, string $now): void
    {
        $query = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.users'))
            ->columns($this->quoteNames([
                'id', 'email', 'email_normalized', 'display_name', 'status', 'version', 'created_at', 'updated_at',
            ]))
            ->values(':id, :email, :email_normalized, :display_name, :status, 1, :created_at, :updated_at')
            ->bind(':id', $id, ParameterType::STRING)
            ->bind(':email', $email->value(), ParameterType::STRING)
            ->bind(':email_normalized', $email->value(), ParameterType::STRING)
            ->bind(':display_name', $displayName, ParameterType::STRING)
            ->bind(':status', 'active', ParameterType::STRING)
            ->bind(':created_at', $now, ParameterType::STRING)
            ->bind(':updated_at', $now, ParameterType::STRING);
        $this->database->setQuery($query)->execute();
    }

    private function insertCredential(string $userId, string $passwordHash, string $now): void
    {
        $query = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.password_credentials'))
            ->columns($this->quoteNames(['user_id', 'password_hash', 'changed_at']))
            ->values(':user_id, :password_hash, :changed_at')
            ->bind(':user_id', $userId, ParameterType::STRING)
            ->bind(':password_hash', $passwordHash, ParameterType::STRING)
            ->bind(':changed_at', $now, ParameterType::STRING);
        $this->database->setQuery($query)->execute();
    }

    private function insertAdministratorRole(string $roleId, string $userId, string $now): void
    {
        $role = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.roles'))
            ->columns($this->quoteNames(['id', 'code', 'name', 'created_at']))
            ->values(':id, :code, :name, :created_at')
            ->bind(':id', $roleId, ParameterType::STRING)
            ->bind(':code', 'administrator', ParameterType::STRING)
            ->bind(':name', 'Administrator', ParameterType::STRING)
            ->bind(':created_at', $now, ParameterType::STRING);
        $this->database->setQuery($role)->execute();

        $assignment = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.user_roles'))
            ->columns($this->quoteNames(['user_id', 'role_id', 'assigned_at', 'assigned_by']))
            ->values(':user_id, :role_id, :assigned_at, :assigned_by')
            ->bind(':user_id', $userId, ParameterType::STRING)
            ->bind(':role_id', $roleId, ParameterType::STRING)
            ->bind(':assigned_at', $now, ParameterType::STRING)
            ->bind(':assigned_by', $userId, ParameterType::STRING);
        $this->database->setQuery($assignment)->execute();

        foreach (self::ADMINISTRATOR_CAPABILITIES as $capability) {
            $grantId = Uuid::uuid7()->toString();
            $grant = $this->database->getQuery(true)
                ->insert($this->quoteName($this->schema . '.role_capability_grants'))
                ->columns($this->quoteNames([
                    'id', 'role_id', 'capability_code', 'scope_type', 'scope_identifier', 'granted_at', 'granted_by',
                ]))
                ->values(':id, :role_id, :capability, :scope_type, NULL, :granted_at, :granted_by')
                ->bind(':id', $grantId, ParameterType::STRING)
                ->bind(':role_id', $roleId, ParameterType::STRING)
                ->bind(':capability', $capability, ParameterType::STRING)
                ->bind(':scope_type', 'global', ParameterType::STRING)
                ->bind(':granted_at', $now, ParameterType::STRING)
                ->bind(':granted_by', $userId, ParameterType::STRING);
            $this->database->setQuery($grant)->execute();
        }
    }

    /** @return list<string> */
    private function capabilitiesFor(string $userId): array
    {
        $query = $this->database->getQuery(true)
            ->select('DISTINCT ' . $this->quoteName('g.capability_code'))
            ->from($this->quoteName($this->schema . '.user_roles', 'ur'))
            ->join(
                'INNER',
                $this->quoteName($this->schema . '.role_capability_grants', 'g')
                    . ' ON ' . $this->quoteName('g.role_id') . ' = ' . $this->quoteName('ur.role_id'),
            )
            ->where($this->quoteName('ur.user_id') . ' = :user_id')
            ->where($this->quoteName('g.scope_type') . " = 'global'")
            ->order($this->quoteName('g.capability_code'))
            ->bind(':user_id', $userId, ParameterType::STRING);
        $values = $this->database->setQuery($query)->loadColumn();

        if (!is_array($values)) {
            throw new RuntimeException('The capability query returned an invalid result set.');
        }

        return array_values(array_filter($values, 'is_string'));
    }

    private function recordAttempt(string $subjectDigest, string $sourceDigest, bool $succeeded): void
    {
        $query = $this->database->getQuery(true)
            ->insert($this->quoteName($this->schema . '.authentication_attempts'))
            ->columns($this->quoteNames(['id', 'subject_digest', 'source_digest', 'succeeded', 'occurred_at']))
            ->values(':id, :subject_digest, :source_digest, :succeeded, :occurred_at')
            ->bind(':id', Uuid::uuid7()->toString(), ParameterType::STRING)
            ->bind(':subject_digest', $subjectDigest, ParameterType::STRING)
            ->bind(':source_digest', $sourceDigest, ParameterType::STRING)
            ->bind(':succeeded', $succeeded, ParameterType::BOOLEAN)
            ->bind(':occurred_at', $this->clock->now()->format('Y-m-d H:i:s.uP'), ParameterType::STRING);
        $this->database->setQuery($query)->execute();
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @param list<string> $names @return list<string> */
    private function quoteNames(array $names): array
    {
        return array_map(fn (string $name): string => $this->quoteName($name), $names);
    }

    private function quoteName(string $name, ?string $alias = null): string
    {
        $quoted = $this->database->quoteName($name, $alias);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted identifier.');
        }

        return $quoted;
    }
}
