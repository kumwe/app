<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

/**
 * One classified difference between the published definition version and the draft that would replace it.
 *
 * `BusinessDefinitionCompatibilityAnalyzer` emits one of these per difference it finds, and
 * `CompatibilityPlan` orders and carries them into the publication gate. The path is a slash-separated
 * pointer into the definition document — `/fields/title/required`, `/relationships/author`, `/workflow` —
 * so a reviewer reading a plan sees which part of the contract moved rather than a diff of the whole
 * payload, and the classification is what decides whether publishing needs an explicit confirmation. The
 * pointer charset and the message ceiling are enforced up front because a change, once emitted, becomes
 * part of an immutable published plan that no later correction can rewrite.
 *
 * @since  2.0.0
 */
final readonly class CompatibilityChange
{
    /**
     * Record one difference, validating that it can be published as part of an immutable plan.
     *
     * @param   string                       $path            Pointer to the part of the definition that moved,
     *          for example `/fields/title/required`.
     * @param   CompatibilityClassification  $classification  How severe the change is for stored records.
     * @param   string                       $message         Operator-facing sentence describing the change.
     *
     * @throws  InvalidBusinessDefinition  When the path is not a slash-led lowercase pointer, or the message
     *          is empty or longer than 500 characters.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $path,
        public CompatibilityClassification $classification,
        public string $message,
    ) {
        if (preg_match('#^/[a-z0-9_./-]+$#D', $path) !== 1 || $message === '' || strlen($message) > 500) {
            throw new InvalidBusinessDefinition('A compatibility change is invalid.');
        }
    }

    /**
     * Export the change as the document embedded in a stored plan and returned by the definitions API.
     *
     * @return  array{path: string, classification: string, message: string}  The pointer, the classification's
     *          backing value, and the operator message.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'classification' => $this->classification->value,
            'message' => $this->message,
        ];
    }
}
