<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessDefinition\Domain\FieldTypeDefinition;

interface ExtensionContributionRegistrar
{
    public function capability(CapabilityDefinition $definition): void;

    public function administratorWorkspace(AdministratorWorkspaceDefinition $definition): void;

    public function administratorNavigation(AdministratorNavigationDefinition $definition): void;

    public function administratorView(AdministratorViewDefinition $definition): void;

    public function administratorRoute(
        AdministratorRouteDefinition $definition,
        AdministratorRouteHandlerFactory $factory,
    ): void;

    public function fieldType(FieldTypeDefinition $definition): void;

    public function businessDefinition(EntityTypeDefinition $definition): void;
}
