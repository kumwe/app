<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Doctrine\DBAL\Connection;

interface Migration
{
    public function id(): string;

    public function checksum(): string;

    public function up(Connection $database): void;
}
