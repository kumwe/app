<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Presentation\ThemeSurface;
use LogicException;

final readonly class ThemeCapabilityPolicy implements ThemeMutationAuthorizer
{
    public function assertSurface(ExecutionContext $context, ThemeSurface $surface): void
    {
        $principal = $context->principal();
        if ($principal === null) {
            return;
        }
        $capability = 'themes.' . $surface->value . '.manage';
        if (!$principal->hasCapability(Capability::fromString($capability))) {
            throw new InsufficientCapability($capability);
        }
    }

    /**
     * @param list<array<string, mixed>> $installed
     */
    public function assertExistingThemeSurfaces(
        ExecutionContext $context,
        string $identifier,
        array $installed,
    ): void {
        foreach ($installed as $extension) {
            if (($extension['identifier'] ?? null) !== $identifier) {
                continue;
            }

            $surfaces = $extension['theme_surfaces'] ?? [];
            if (!is_array($surfaces) || !array_is_list($surfaces)) {
                throw new LogicException('The extension manager returned invalid theme surfaces.');
            }

            foreach ($surfaces as $surface) {
                if (!is_string($surface) || ThemeSurface::tryFrom($surface) === null) {
                    throw new LogicException('The extension manager returned an invalid theme surface.');
                }
                $this->assertSurface($context, ThemeSurface::from($surface));
            }

            return;
        }
    }
}
