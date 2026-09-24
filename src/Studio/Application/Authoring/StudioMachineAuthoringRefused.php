<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\Producer\Error\HostError;
use RuntimeException;
use stdClass;

/**
 * One Studio authoring refusal carried to a machine adapter in Producer's own canonical taxonomy.
 *
 * The browser receives every refusal as a `host-error` document. A machine adapter receives exactly the
 * same document through this exception, so REST, CLI and MCP map one category, one diagnostic list and
 * one safe revision onto their transport instead of re-deciding what was refused. The stable code
 * exposes the category to closed vocabularies; the two idempotency diagnostics are lifted into codes of
 * their own because every machine surface already distinguishes a reused key from a stale revision.
 *
 * @since  2.0.0
 */
final class StudioMachineAuthoringRefused extends RuntimeException
{
    /**
     * Diagnostic codes that mean the caller reused an idempotency key for a different intent.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array KEY_REUSED_DIAGNOSTICS = [
        'kumwe.producer/idempotent-intent-changed',
        'studio.host/idempotency-intent-changed',
    ];

    /**
     * Diagnostic codes that mean the first attempt under this key is still running.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array IN_PROGRESS_DIAGNOSTICS = [
        'studio.host/idempotency-in-progress',
    ];

    /**
     * Wrap one canonical Producer refusal.
     *
     * @param  HostError  $error  Canonical error exactly as Producer would serialize it for the browser.
     *
     * @since  2.0.0
     */
    public function __construct(public readonly HostError $error)
    {
        parent::__construct('The Studio authoring request was refused.');
    }

    /**
     * Build a refusal from delivery-safe App facts, using the same constructor the host ports use.
     *
     * @param   string   $category        Closed Producer refusal category.
     * @param   string   $diagnosticCode  Stable delivery-safe diagnostic code.
     * @param   ?string  $revision        Safe current revision for a conflict.
     * @param   bool     $retryable       Whether an unavailable refusal is transient.
     *
     * @return  self  Refusal carrying the canonical error.
     *
     * @since   2.0.0
     */
    public static function of(
        string $category,
        string $diagnosticCode,
        ?string $revision = null,
        bool $retryable = false,
    ): self {
        return new self(StudioProducerError::error($category, $diagnosticCode, $revision, $retryable));
    }

    /**
     * The closed Producer category of the refusal.
     *
     * @return  string  One of Producer's twelve categories, such as `forbidden` or `conflict`.
     *
     * @since   2.0.0
     */
    public function category(): string
    {
        return $this->error->category();
    }

    /**
     * Every diagnostic code the refusal carries, in the order Producer emits them.
     *
     * @return  list<string>  Stable diagnostic codes such as `studio.authoring/expected-mismatch`.
     *
     * @since   2.0.0
     */
    public function diagnosticCodes(): array
    {
        $codes = [];
        foreach ($this->error->diagnostics() as $diagnostic) {
            $codes[] = $diagnostic->code();
        }

        return $codes;
    }

    /**
     * The safe current revision a conflict discloses.
     *
     * @return  ?string  Revision, or null for every other category.
     *
     * @since   2.0.0
     */
    public function revision(): ?string
    {
        return $this->error->revision();
    }

    /**
     * Whether an unchanged later attempt may succeed.
     *
     * @return  bool  Producer's retry decision for this refusal.
     *
     * @since   2.0.0
     */
    public function retryable(): bool
    {
        return $this->error->retryable();
    }

    /**
     * The HTTP status Producer's category pairs with.
     *
     * @return  int  Status preserving the refusal's semantic category.
     *
     * @since   2.0.0
     */
    public function status(): int
    {
        return StudioProducerError::status($this->category());
    }

    /**
     * The canonical `host-error` document, byte-equivalent to what the browser receives.
     *
     * @return  stdClass  Decoded canonical error document.
     *
     * @since   2.0.0
     */
    public function document(): stdClass
    {
        return $this->error->toDocument();
    }

    /**
     * Whether the caller reused an idempotency key for a different request.
     *
     * @return  bool  True when either idempotency intent-changed diagnostic is present.
     *
     * @since   2.0.0
     */
    public function keyReused(): bool
    {
        return array_intersect($this->diagnosticCodes(), self::KEY_REUSED_DIAGNOSTICS) !== [];
    }

    /**
     * Whether the first attempt under the caller's idempotency key is still running.
     *
     * @return  bool  True when the in-progress diagnostic is present.
     *
     * @since   2.0.0
     */
    public function inProgress(): bool
    {
        return array_intersect($this->diagnosticCodes(), self::IN_PROGRESS_DIAGNOSTICS) !== [];
    }

    /**
     * The dotted stable code closed machine vocabularies classify this refusal by.
     *
     * @return  string  `studio_authoring.` followed by the category, or one of the two idempotency codes.
     *
     * @since   2.0.0
     */
    public function stableCode(): string
    {
        if ($this->keyReused()) {
            return 'studio_authoring.idempotency_key_reused';
        }
        if ($this->inProgress()) {
            return 'studio_authoring.idempotency_in_progress';
        }

        return 'studio_authoring.' . str_replace('-', '_', $this->category());
    }
}
