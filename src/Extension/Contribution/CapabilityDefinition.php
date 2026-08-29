<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionDefinition;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\Extension\Spi\Identity\Domain\Capability;

/**
 * One capability a contributor adds to the permission vocabulary, with the wording an operator reads.
 *
 * Administrator routes and navigation items are guarded by capability identifiers, and each may only
 * name a capability its own owner registered, so contributing a capability is the first step in
 * contributing anything reachable. Validation happens here rather than at registration: the
 * identifier is normalised through `Capability`, its texts and allowed scopes are bounded, and
 * delegation, impact, lifecycle, and version are explicit rather than inferred by the gateway.
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
     * Grant scope types the capability permits, sorted and de-duplicated; empty means system-only.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public array $allowedScopes;

    /**
     * Normalise and bounds-check one contributed capability.
     *
     * @param   string                            $id             Capability identifier; normalized by `Capability`.
     * @param   string                            $label          Display name of 1 to 100 characters, stored trimmed.
     * @param   string                            $description    Operator-facing explanation of 1 to 500 characters.
     * @param   iterable<string>                  $allowedScopes  Grant-scope types; empty makes it system-only.
     * @param   bool                              $delegatable    Whether a human may grant it onward.
     * @param   bool                              $highImpact     Whether high-impact controls apply above grants.
     * @param   AuthorizationDefinitionLifecycle  $lifecycle      Current runtime lifecycle state.
     * @param   int                               $version        Positive definition version owned by the contributor.
     *
     * @throws  InvalidArgumentException  When the identifier is malformed or a text falls outside its range.
     *
     * @since   2.0.0
     */
    public function __construct(
        string $id,
        string $label,
        string $description,
        iterable $allowedScopes = ['global', 'site'],
        public bool $delegatable = true,
        public bool $highImpact = false,
        public AuthorizationDefinitionLifecycle $lifecycle = AuthorizationDefinitionLifecycle::Active,
        public int $version = 1,
    ) {
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
        if ($version < 1) {
            throw new InvalidArgumentException('A contributed capability version must be positive.');
        }
        $scopes = [];
        foreach ($allowedScopes as $scope) {
            if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $scope) !== 1) {
                throw new InvalidArgumentException('A contributed capability scope must be a lowercase identifier.');
            }
            $scopes[$scope] = true;
        }
        if (count($scopes) > 64) {
            throw new InvalidArgumentException('A contributed capability may declare at most 64 scopes.');
        }
        ksort($scopes, SORT_STRING);
        $this->allowedScopes = array_keys($scopes);
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
     * @return  array{
     *              id: string,
     *              label: string,
     *              description: string,
     *              allowed_scopes: list<string>,
     *              delegatable: bool,
     *              high_impact: bool,
     *              lifecycle: string,
     *              version: int
     *          }
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'allowed_scopes' => $this->allowedScopes,
            'delegatable' => $this->delegatable,
            'high_impact' => $this->highImpact,
            'lifecycle' => $this->lifecycle->value,
            'version' => $this->version,
        ];
    }
}
