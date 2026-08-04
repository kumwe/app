<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Package;

enum ArchiveEntryType: string
{
    case File = 'file';
    case Directory = 'directory';
    case SymbolicLink = 'symbolic_link';
}
