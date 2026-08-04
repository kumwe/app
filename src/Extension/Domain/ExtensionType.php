<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

enum ExtensionType: string
{
    case Plugin = 'plugin';
    case Module = 'module';
    case Template = 'template';
    case Component = 'component';
    case Package = 'package';
    case Language = 'language';
}
