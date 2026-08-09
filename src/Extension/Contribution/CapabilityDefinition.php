<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

/**
 * One capability a contributor adds to the permission vocabulary, with the wording an operator reads.
 *
 * Administrator routes and navigation items are guarded by capability identifiers, and each may only
 * name a capability its own owner registered, so contributing a capability is the first step in
 * contributing anything reachable. Validation happens here rather than at registration: the
 * identifier is normalised through `Capability` and both texts are length-bounded, so the registry
 * and the installer that writes these rows can take a manifest's wording as given.
 *
 * @since  2.0.0
 */
final readonly class CapabilityDefinition implements ContributionDefinition
{
    /**
     * Normalised capability identifier this definition contributes, such as `content.read`.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $id;

    /**
     * Short display name for the capability, carried through the contribution export.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $label;

    /**
     * Sentence telling an operator what granting this allows; the installer stores it on the capability row.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $description;

    /**
     * Normalise and bounds-check one contributed capability.
     *
     * @param   string  $id           Capability identifier; lowercased and validated by `Capability`.
     * @param   string  $label        Display name of 1 to 100 characters, stored trimmed.
     * @param   string  $description  Operator-facing explanation of 1 to 500 characters, stored trimmed.
     *
     * @throws  InvalidArgumentException  When the identifier is malformed or a text falls outside its range.
     *
     * @since   2.0.0
     */
    public function __construct(string $id, string $label, string $description)
    {
        $this->id = Capability::fromString($id)->value();
        $label = trim($label);
        $description = trim($description);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new InvalidArgumentException('A contributed capability label must contain 1 to 100 characters.');
        }
        if ($description === '' || mb_strlen($description) > 500) {
            throw new InvalidArgumentException(
                'A contributed capability description must contain 1 to 500 characters.',
            );
        }
        $this->label = $label;
        $this->description = $description;
    }

    /**
     * The identifier the registrar indexes this contribution under.
     *
     * @return  string  The normalised capability identifier, the same value as `$id`.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->id;
    }

    /**
     * Export the capability in the shape a manifest declares and an inventory reports.
     *
     * @return  array{id: string, label: string, description: string}  Identifier with its two texts.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'description' => $this->description];
    }
}
