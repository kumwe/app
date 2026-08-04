<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Infrastructure\Authentication;

use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use JsonException;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use LogicException;

final readonly class PostgreSqlAccessTokenVerifier implements AccessTokenVerifier
{
    public function __construct(private DatabaseInterface $database, private string $schema)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
    }

    public function verify(string $token): ?AuthenticatedPrincipal
    {
        if (!$this->isValidTokenShape($token)) {
            return null;
        }

        $digest = hash('sha256', $token);
        $query = $this->database->getQuery(true)
            ->select([
                $this->quoteName('t.subject_id'),
                $this->quoteName('t.capabilities'),
            ])
            ->from($this->quoteName($this->schema . '.api_tokens', 't'))
            ->join(
                'INNER',
                $this->quoteName($this->schema . '.users', 'u')
                    . ' ON ' . $this->quoteName('u.id')
                    . ' = ' . $this->quoteName('t.subject_id'),
            )
            ->where($this->quoteName('t.token_digest') . ' = :token_digest')
            ->where($this->quoteName('t.revoked_at') . ' IS NULL')
            ->where('(' . $this->quoteName('t.expires_at') . ' IS NULL OR '
                . $this->quoteName('t.expires_at') . ' > CURRENT_TIMESTAMP)')
            ->where($this->quoteName('u.status') . ' = ' . $this->quoteValue('active'))
            ->bind(':token_digest', $digest, ParameterType::STRING);

        $row = $this->database->setQuery($query)->loadAssoc();

        if (!is_array($row)) {
            return null;
        }

        try {
            $capabilities = $this->decodeCapabilities($row['capabilities'] ?? null);
            $subject = $row['subject_id'] ?? null;

            if (!is_string($subject)) {
                return null;
            }

            return AuthenticatedPrincipal::fromStrings($subject, $capabilities);
        } catch (InvalidArgumentException | JsonException) {
            return null;
        }
    }

    private function isValidTokenShape(string $token): bool
    {
        $length = strlen($token);

        return $length >= 32
            && $length <= 512
            && preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) === 1;
    }

    /**
     * @return list<string>
     *
     * @throws JsonException
     */
    private function decodeCapabilities(mixed $stored): array
    {
        if (is_string($stored)) {
            $stored = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
        }

        if (!is_array($stored) || !array_is_list($stored)) {
            throw new InvalidArgumentException('Stored token capabilities must be a JSON list.');
        }

        foreach ($stored as $capability) {
            if (!is_string($capability)) {
                throw new InvalidArgumentException('Stored token capabilities must contain strings.');
            }
        }

        return $stored;
    }

    private function quoteName(string $name, ?string $alias = null): string
    {
        $quoted = $this->database->quoteName($name, $alias);

        if (!is_string($quoted)) {
            throw new LogicException('Joomla Database returned an invalid quoted identifier.');
        }

        return $quoted;
    }

    private function quoteValue(string $value): string
    {
        $quoted = $this->database->quote($value);

        if (!is_string($quoted)) {
            throw new LogicException('Joomla Database returned an invalid quoted value.');
        }

        return $quoted;
    }
}
