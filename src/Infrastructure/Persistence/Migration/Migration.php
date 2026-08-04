<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use Joomla\Database\DatabaseInterface;

interface Migration
{
    public function id(): string;

    public function checksum(): string;

    public function up(DatabaseInterface $database): void;
}
