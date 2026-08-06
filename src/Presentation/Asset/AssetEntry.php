<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Asset;

final readonly class AssetEntry
{
    /**
     * @param list<string> $stylesheets
     * @param list<string> $modules
     */
    public function __construct(public array $stylesheets, public array $modules)
    {
    }

    /** @return array{stylesheets: list<string>, modules: list<string>} */
    public function toArray(): array
    {
        return ['stylesheets' => $this->stylesheets, 'modules' => $this->modules];
    }
}
