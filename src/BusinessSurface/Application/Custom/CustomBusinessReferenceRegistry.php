<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

use InvalidArgumentException;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;

/**
 * Coordinates handler and schema reference claims across both custom contribution families.
 *
 * View and action registries are separate typed dispatchers, while their references share one published
 * namespace. This small collaborator prevents a handler in one family from shadowing a schema or handler
 * in the other, and releases only the exact claims withdrawn by a registry.
 *
 * @since  2.0.0
 */
final class CustomBusinessReferenceRegistry
{
    /**
     * Active claims keyed by owner-scoped reference.
     *
     * @var    array<string, array{owner: array{type: string, identifier: string}, kind: string}>
     * @since  2.0.0
     */
    private array $claims = [];

    /**
     * Atomically claim the distinct handler and schema references for one typed contract.
     *
     * @param   DefinitionOwner  $owner    Contributor claiming both references.
     * @param   string           $handler  Handler reference to reserve.
     * @param   string           $schema   Schema reference to reserve.
     * @param   string           $kind     View or action kind used in stable diagnostics.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership fails or either reference is already claimed.
     *
     * @since   2.0.0
     */
    public function claim(DefinitionOwner $owner, string $handler, string $schema, string $kind): void
    {
        $owner->assertOwns($handler);
        $owner->assertOwns($schema);
        foreach ([$handler, $schema] as $reference) {
            if (isset($this->claims[$reference])) {
                throw new InvalidArgumentException(sprintf(
                    'Custom business reference %s is already claimed by a %s contract.',
                    $reference,
                    $this->claims[$reference]['kind'],
                ));
            }
        }
        $this->claims[$handler] = ['owner' => $owner->toArray(), 'kind' => $kind . ' handler'];
        $this->claims[$schema] = ['owner' => $owner->toArray(), 'kind' => $kind . ' schema'];
        ksort($this->claims, SORT_STRING);
    }

    /**
     * Release one exact handler/schema pair when its owning registry withdraws it.
     *
     * @param   DefinitionOwner  $owner    Contributor expected to hold both claims.
     * @param   string           $handler  Handler reference being withdrawn.
     * @param   string           $schema   Schema reference being withdrawn.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function release(DefinitionOwner $owner, string $handler, string $schema): void
    {
        foreach ([$handler, $schema] as $reference) {
            if (($this->claims[$reference]['owner'] ?? null) === $owner->toArray()) {
                unset($this->claims[$reference]);
            }
        }
    }
}
