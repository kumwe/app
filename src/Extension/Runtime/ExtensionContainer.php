<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

interface ExtensionContainer
{
    public function get(string $id): object;

    /** @param callable(ExtensionContainer): object $factory */
    public function share(string $id, callable $factory): void;
}
