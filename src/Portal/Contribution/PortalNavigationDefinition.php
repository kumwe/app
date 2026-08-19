<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\ContributionDefinition;
use Kumwe\App\Identity\Domain\Capability;
use Kumwe\App\InterfaceStandard\SurfaceId;

/**
 * Capability-gated navigation declaration explicitly opted into the portal shell.
 *
 * @since  2.0.0
 */
final readonly class PortalNavigationDefinition implements ContributionDefinition
{
    /**
     * Normalized required capability.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $capability;

    /**
     * Validate one portal navigation item.
     *
     * @param   string   $id           Owner-scoped dotted identifier.
     * @param   string   $workspace    Owner-scoped workspace identifier.
     * @param   string   $label        Visible text, 1 through 80 characters.
     * @param   string   $description  Accessible explanation, 1 through 255 characters.
     * @param   string   $path         Absolute safe path, later rooted for extensions.
     * @param   string   $icon         Portable lowercase icon token.
     * @param   string   $capability   Required capability owned by the same contributor.
     * @param   int      $priority     Sort weight from 0 through 100000.
     * @param   string   $keywords     Optional search text up to 500 characters.
     * @param   ?string  $surface      Stable KIS surface identifier, or null for a legacy pre-KIS package.
     *
     * @throws  InvalidArgumentException  When any field is unsafe or outside its bound.
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
        PortalWorkspaceDefinition::assertIdentifier($id, 'navigation');
        PortalWorkspaceDefinition::assertIdentifier($workspace, 'workspace');
        if ($surface !== null) {
            SurfaceId::fromString($surface);
        }
        $this->capability = Capability::fromString($capability)->value();
        if (
            trim($label) === ''
            || mb_strlen($label) > 80
            || trim($description) === ''
            || mb_strlen($description) > 255
        ) {
            throw new InvalidArgumentException('Portal navigation labels or descriptions are invalid.');
        }
        if (preg_match('#^/(?:[a-z0-9][a-z0-9-]*(?:/|$))*$#D', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('A contributed portal navigation path is unsafe.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $icon) !== 1) {
            throw new InvalidArgumentException('A contributed portal navigation icon is invalid.');
        }
        if ($priority < 0 || $priority > 100_000 || mb_strlen($keywords) > 500) {
            throw new InvalidArgumentException('Portal navigation ordering or keywords are invalid.');
        }
    }

    /**
     * Return the claimed navigation identifier.
     *
     * @return  string  Dotted item identifier.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->id;
    }

    /**
     * Export every declared field for manifest comparison.
     *
     * @return  array<string, int|string>  Stable declaration shape.
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
