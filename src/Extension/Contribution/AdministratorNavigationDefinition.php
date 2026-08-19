<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\InterfaceStandard\SurfaceId;

/**
 * One validated entry in the administrator menu, contributed by core or by an installed extension.
 *
 * Everything an extension can get wrong is settled here, at construction: the identifier and workspace
 * shapes, the label and description lengths, a path that cannot traverse upwards or carry anything but
 * lowercase segments, an icon name, and an ordering budget. That is what lets
 * `AdministratorNavigationRegistry` sort, filter and render contributed entries without re-checking
 * anything an extension supplied, and it is why an untrusted manifest cannot smuggle a link somewhere
 * the administrator does not intend. The capability is normalized through `Capability` so visibility
 * comes down to a lookup against the actor's capability set.
 *
 * @since  2.0.0
 */
final readonly class AdministratorNavigationDefinition implements ContributionDefinition
{
    /**
     * Capability an actor must hold before this entry is shown to them.
     *
     * Assigned rather than promoted because the constructor normalizes the supplied spelling through
     * `Capability` first, so the registry can compare it directly against a capability set.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $capability;

    /**
     * Validate a contributed menu entry and normalize its capability.
     *
     * @param   string   $id           Dot-separated identifier of the entry, which its contributing
     *          extension must own.
     * @param   string   $workspace    Dot-separated identifier of the workspace the entry is grouped under.
     * @param   string   $label        Menu text, 1 to 80 characters.
     * @param   string   $description  Sentence shown with the label, 1 to 255 characters.
     * @param   string   $path         Absolute path of lowercase segments; an extension's entry is later
     *          re-rooted under its own `/administrator/extensions/...` prefix.
     * @param   string   $icon         Lowercase icon name the administrator theme resolves.
     * @param   string   $capability   Capability required to see the entry, stored normalized in
     *          `$this->capability`.
     * @param   int      $priority     Sort weight within the workspace, 0 to 100000, lower shown first.
     * @param   string   $keywords     Extra search terms, up to 500 characters; empty when the label and
     *          description already carry them.
     * @param   ?string  $surface      Stable KIS surface identifier, or null for a legacy pre-KIS package.
     *
     * @throws  InvalidArgumentException  When the entry or workspace identifier is not dotted lowercase,
     *          the capability is not a valid capability name, the label, description,
     *          priority or keywords fall outside their limits, or the path or icon is
     *          unsafe.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $workspace,
        public string $label,
        public string $description,
        public string $path,
        public string $icon,
        string $capability,
        public int $priority,
        public string $keywords = '',
        public ?string $surface = null,
    ) {
        AdministratorWorkspaceDefinition::assertIdentifier($id, 'navigation');
        AdministratorWorkspaceDefinition::assertIdentifier($workspace, 'workspace');
        if ($surface !== null) {
            SurfaceId::fromString($surface);
        }
        $this->capability = Capability::fromString($capability)->value();
        if (trim($label) === '' || mb_strlen($label) > 80) {
            throw new InvalidArgumentException('An administrator navigation label must contain 1 to 80 characters.');
        }
        if (trim($description) === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException(
                'An administrator navigation description must contain 1 to 255 characters.',
            );
        }
        if (preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/|$))*$#D', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('A contributed administrator navigation path is unsafe.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $icon) !== 1) {
            throw new InvalidArgumentException('A contributed administrator navigation icon is invalid.');
        }
        if ($priority < 0 || $priority > 100_000 || mb_strlen($keywords) > 500) {
            throw new InvalidArgumentException('Administrator navigation ordering or keywords are invalid.');
        }
    }

    /**
     * Report the identifier the contribution registries file this entry under.
     *
     * @return  string  The entry's own dotted identifier, distinct from the workspace it sits in.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->id;
    }

    /**
     * Export the entry for the manifest contribution set and the signed runtime publication.
     *
     * The exported capability is the normalized one, so a manifest that spelled it differently still
     * round-trips to the same compiled contribution.
     *
     * @return  array<string, int|string>  Every declared field, keyed by its own name.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        $document = [
            'id' => $this->id,
            'workspace' => $this->workspace,
            'label' => $this->label,
            'description' => $this->description,
            'path' => $this->path,
            'icon' => $this->icon,
            'capability' => $this->capability,
            'priority' => $this->priority,
            'keywords' => $this->keywords,
        ];
        if ($this->surface !== null) {
            $document['surface'] = $this->surface;
        }

        return $document;
    }
}
