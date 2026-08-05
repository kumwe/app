<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

interface ExtensionArtifactVerifier
{
    /** @param array<string, mixed> $release */
    public function assertMatches(array $release): void;
}
