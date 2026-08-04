<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

enum InstallAction: string
{
    case VerifyChecksum = 'verify_checksum';
    case InspectArchive = 'inspect_archive';
    case VerifyTrust = 'verify_trust';
    case ResolveDependencies = 'resolve_dependencies';
    case StageFiles = 'stage_files';
    case ApplyMigrations = 'apply_migrations';
    case RegisterExtension = 'register_extension';
    case ActivateFiles = 'activate_files';
    case RebuildCaches = 'rebuild_caches';
}
