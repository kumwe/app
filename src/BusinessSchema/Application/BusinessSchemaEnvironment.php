<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Application;

interface BusinessSchemaEnvironment
{
    public function databaseDriver(): string;

    public function databaseServerVersion(): string;

    public function applicationRelease(): string;
}
