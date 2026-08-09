<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * The complete machine-readable account of what publishing a draft would change, and what that costs.
 *
 * `BusinessDefinitionCompatibilityAnalyzer` builds one by comparing the published head against the draft;
 * `BusinessDefinitionService` refuses to publish when the plan requires a confirmation the caller did not
 * give, then stores the plan alongside the version it published. Carrying both version numbers and both
 * checksums is what lets a stored plan be tied back later to exactly the bytes it described, and sorting
 * the changes at construction is what makes the serialized plan reproducible: the same pair of
 * definitions yields identical bytes no matter what order the analyzer happened to discover differences
 * in.
 *
 * @since  2.0.0
 */
final readonly class CompatibilityPlan
{
    /**
     * Differences between the two versions, ordered so the plan serializes reproducibly.
     *
     * @var    list<CompatibilityChange>
     * @since  2.0.0
     */
    private array $changes;

    /**
     * Assemble a plan, validating its version bounds and checksums and putting its changes in order.
     *
     * Sorting is by path, then classification, then message, which is a total order over the change
     * documents themselves rather than over object identity, so it is stable across processes.
     *
     * @param   ?int                       $fromVersion   Version being replaced, or null when this would be the
     *          first published version.
     * @param   int                        $toVersion     Version this plan would publish.
     * @param   ?string                    $fromChecksum  Canonical checksum of the version being replaced, or
     *          null when there is none.
     * @param   string                     $toChecksum    Canonical checksum of the definition to be published.
     * @param   list<CompatibilityChange>  $changes       Differences found by the analyzer, in any order.
     *
     * @throws  InvalidBusinessDefinition  When the target version is below one, a replacement does not advance
     *          the version by exactly one, or a checksum is not 64 lowercase hexadecimal characters.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ?int $fromVersion,
        public int $toVersion,
        public ?string $fromChecksum,
        public string $toChecksum,
        array $changes,
    ) {
        if ($toVersion < 1 || ($fromVersion !== null && $toVersion !== $fromVersion + 1)) {
            throw new InvalidBusinessDefinition('A compatibility plan has invalid version bounds.');
        }
        foreach (array_filter([$fromChecksum, $toChecksum], 'is_string') as $checksum) {
            if (preg_match('/^[a-f0-9]{64}$/D', $checksum) !== 1) {
                throw new InvalidBusinessDefinition('A compatibility plan checksum is invalid.');
            }
        }
        usort($changes, static fn (CompatibilityChange $left, CompatibilityChange $right): int => [
            $left->path,
            $left->classification->value,
            $left->message,
        ] <=> [
            $right->path,
            $right->classification->value,
            $right->message,
        ]);
        $this->changes = $changes;
    }

    /**
     * Return the classified differences, ordered by path, then classification, then message.
     *
     * @return  list<CompatibilityChange>  Empty when nothing about the contract moved between the two
     *          versions; a first publication always reports at least the creation itself.
     *
     * @since   2.0.0
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * Whether publishing this plan needs the publisher to acknowledge its consequences first.
     *
     * True as soon as a single change is anything other than additive. `BusinessDefinitionService::publish()`
     * reads this and rejects an unconfirmed publication, so the flag is a gate rather than a hint.
     *
     * @return  bool  True when at least one change requires confirmation.
     *
     * @since   2.0.0
     */
    public function requiresConfirmation(): bool
    {
        return array_filter(
            $this->changes,
            static fn (CompatibilityChange $change): bool => $change->classification->requiresConfirmation(),
        ) !== [];
    }

    /**
     * Whether any change withdraws part of the contract and the data behind it.
     *
     * Reported separately from `requiresConfirmation()` — which is already true for every destructive plan —
     * so a surface can warn more loudly; the administrator publication gate badges the plan on this flag.
     *
     * @return  bool  True when at least one change is classified destructive.
     *
     * @since   2.0.0
     */
    public function destructive(): bool
    {
        return array_filter(
            $this->changes,
            static fn (CompatibilityChange $change): bool =>
                $change->classification === CompatibilityClassification::Destructive,
        ) !== [];
    }

    /**
     * Export the plan as the document stored with the published version, audited, and served to clients.
     *
     * The two derived flags are materialized here rather than recomputed downstream, so a stored plan keeps
     * the verdict that was actually acted on even if the classification rules are later revised.
     *
     * @return  array<string, mixed>  Version bounds under `from_version` and `to_version`, both checksums, the
     *          `requires_confirmation` and `destructive` verdicts, and the ordered change documents under
     *          `changes`.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'from_checksum' => $this->fromChecksum,
            'to_checksum' => $this->toChecksum,
            'requires_confirmation' => $this->requiresConfirmation(),
            'destructive' => $this->destructive(),
            'changes' => array_map(
                static fn (CompatibilityChange $change): array => $change->toArray(),
                $this->changes,
            ),
        ];
    }
}
