<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Automation;

use DateInterval;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Kumwe\App\Shared\Domain\CanonicalJson;

/**
 * Short-lived preview of a command, fingerprinted so it can only be applied exactly as previewed.
 *
 * A plan captures a command name and its arguments and hashes the pair through canonical JSON. To
 * apply it, a caller presents that digest back: a payload that merely reordered its keys still
 * matches, while a payload whose values changed does not, which closes the window between showing an
 * operator what will happen and doing it. The plan also carries a deliberate expiry so a stale
 * preview cannot be acted on, and a plan created with `ConfirmationRequirement::EXPLICIT` demands a
 * confirmation token derived from the same digest before it will apply.
 *
 * @since  2.0.0
 */
final readonly class ChangePlan
{
    /**
     * Leading digest characters a confirmation token carries, enough to bind the token to one plan.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int CONFIRMATION_DIGEST_LENGTH = 16;

    /**
     * Arguments the previewed command will be applied with, kept in the order the caller supplied.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    private array $payload;

    /**
     * Fingerprint over the canonical form of the command name and payload.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $digest;

    /**
     * Validate the plan's identity and window, then fingerprint the command it covers.
     *
     * @param   string                   $id                       Identifier callers refer to the plan by.
     * @param   string                   $command                  Name of the command being previewed.
     * @param   array<string, mixed>     $payload                  Arguments the command will be applied with.
     * @param   DateTimeImmutable        $createdAt                Instant the preview was taken.
     * @param   DateTimeImmutable        $expiresAt                Instant the plan stops being applicable.
     * @param   ConfirmationRequirement  $confirmationRequirement  Whether applying it needs an explicit token.
     *
     * @throws  InvalidArgumentException  When the identifier is blank, the command name is not 3 to 128
     *          characters starting with a letter, the expiry is not after creation, or the payload holds
     *          a value canonical JSON cannot represent.
     *
     * @since   2.0.0
     */
    private function __construct(
        private string $id,
        private string $command,
        array $payload,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
        private ConfirmationRequirement $confirmationRequirement,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A change plan ID is required.');
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9._:-]{2,127}$/D', $command) !== 1) {
            throw new InvalidArgumentException('The change plan command name is invalid.');
        }

        if ($expiresAt <= $createdAt) {
            throw new InvalidArgumentException('A change plan must expire after it is created.');
        }

        $this->payload = $payload;
        $this->digest = CanonicalJson::digest([
            'command' => $command,
            'payload' => $payload,
        ]);
    }

    /**
     * Take a preview of a command that stays applicable for a bounded window.
     *
     * @param   string                   $id                       Identifier callers refer to the plan by.
     * @param   string                   $command                  Name of the command being previewed.
     * @param   array<string, mixed>     $payload                  Arguments the command will be applied with.
     * @param   DateTimeImmutable        $createdAt                Instant the preview is taken.
     * @param   int                      $ttlSeconds               Seconds the plan stays applicable for.
     * @param   ConfirmationRequirement  $confirmationRequirement  Whether applying it needs an explicit token.
     *
     * @return  self  Plan whose digest already covers the command name and payload.
     *
     * @throws  InvalidArgumentException  When the lifetime is under one second, or the identifier, command
     *          name or payload fails the constructor's checks.
     *
     * @since   2.0.0
     */
    public static function create(
        string $id,
        string $command,
        array $payload,
        DateTimeImmutable $createdAt,
        int $ttlSeconds,
        ConfirmationRequirement $confirmationRequirement = ConfirmationRequirement::NONE,
    ): self {
        if ($ttlSeconds < 1) {
            throw new InvalidArgumentException('A change plan TTL must be at least one second.');
        }

        return new self(
            $id,
            $command,
            $payload,
            $createdAt,
            $createdAt->add(new DateInterval(sprintf('PT%dS', $ttlSeconds))),
            $confirmationRequirement,
        );
    }

    /**
     * Return the identifier the plan is stored and looked up under.
     *
     * @return  string  The identifier given at creation, holding at least one non-whitespace character.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return the name of the command this plan previews.
     *
     * @return  string  Command name of 3 to 128 characters beginning with a letter, as accepted at creation.
     *
     * @since   2.0.0
     */
    public function command(): string
    {
        return $this->command;
    }

    /**
     * Return the arguments the previewed command will be applied with.
     *
     * @return  array<string, mixed>  The payload as supplied, with its original key order intact.
     *
     * @since   2.0.0
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Return the fingerprint that ties this plan to one exact command and payload.
     *
     * A caller stores this and hands it back to `assertCanApply()`; two plans over equivalent payloads
     * share a digest even when their keys were written in a different order.
     *
     * @return  string  Lowercase SHA-256 hex digest of the canonical JSON for the command and payload.
     *
     * @since   2.0.0
     */
    public function digest(): string
    {
        return $this->digest;
    }

    /**
     * Return the instant the preview was taken.
     *
     * @return  DateTimeImmutable  Creation time exactly as the caller's clock reported it.
     *
     * @since   2.0.0
     */
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Return the instant from which the plan can no longer be applied.
     *
     * @return  DateTimeImmutable  Expiry boundary; the plan already counts as expired at this instant.
     *
     * @since   2.0.0
     */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Report whether applying this plan needs an explicit confirmation token.
     *
     * @return  bool  True only for a plan created with `ConfirmationRequirement::EXPLICIT`.
     *
     * @since   2.0.0
     */
    public function requiresConfirmation(): bool
    {
        return $this->confirmationRequirement === ConfirmationRequirement::EXPLICIT;
    }

    /**
     * Derive the token a caller must echo back to apply a plan that demands explicit confirmation.
     *
     * The token is derived from the digest rather than stored, so it moves with the payload and cannot
     * be replayed against a different plan.
     *
     * @return  ?string  Token to present when applying, or null when this plan needs no confirmation.
     *
     * @since   2.0.0
     */
    public function confirmationToken(): ?string
    {
        if (!$this->requiresConfirmation()) {
            return null;
        }

        return 'confirm-' . substr($this->digest, 0, self::CONFIRMATION_DIGEST_LENGTH);
    }

    /**
     * Decide whether the plan has reached or passed its expiry at a given instant.
     *
     * @param   DateTimeImmutable  $time  Instant to judge the plan against, normally the current clock.
     *
     * @return  bool  True from the expiry instant onward, so the boundary itself counts as expired.
     *
     * @since   2.0.0
     */
    public function isExpiredAt(DateTimeImmutable $time): bool
    {
        return $time >= $this->expiresAt;
    }

    /**
     * Refuse the apply unless the plan is live, still describes the same command, and is confirmed.
     *
     * This is the guard an apply path calls before doing any work. Both comparisons run in constant
     * time, and the supplied digest is lowercased first so a caller may present it in either case.
     *
     * @param   string             $commandDigest      Digest of the command the caller is about to run.
     * @param   DateTimeImmutable  $time               Instant the apply is being attempted at.
     * @param   ?string            $confirmationToken  Token echoed back, or null when none was issued.
     *
     * @return  void
     *
     * @throws  DomainException  When the plan has expired, the digest no longer matches the previewed
     *          command, or a required confirmation token is absent or wrong.
     *
     * @since   2.0.0
     */
    public function assertCanApply(
        string $commandDigest,
        DateTimeImmutable $time,
        ?string $confirmationToken = null,
    ): void {
        if ($this->isExpiredAt($time)) {
            throw new DomainException('The change plan has expired.');
        }

        if (!hash_equals($this->digest, strtolower($commandDigest))) {
            throw new DomainException('The command no longer matches the change plan.');
        }

        $expectedConfirmation = $this->confirmationToken();

        if ($expectedConfirmation !== null && !hash_equals($expectedConfirmation, (string) $confirmationToken)) {
            throw new DomainException('Explicit confirmation is required to apply this change plan.');
        }
    }
}
