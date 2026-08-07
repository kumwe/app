<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessDefinition\Domain;

enum ScopeMode: string
{
    case Installation = 'installation';
    case Site = 'site';
    case Organization = 'organization';
    case SiteOrganization = 'site_organization';
}
