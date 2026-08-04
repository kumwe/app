<?php

declare(strict_types=1);

namespace Kumwe\CMS\Kernel\Configuration;

enum RuntimeEnvironment: string
{
    case Development = 'development';
    case Production = 'production';
    case Testing = 'testing';
}
