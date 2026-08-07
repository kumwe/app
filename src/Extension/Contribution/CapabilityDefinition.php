<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Identity\Domain\Capability;

final readonly class CapabilityDefinition implements ContributionDefinition
{
    public string $id;

    public string $label;

    public string $description;

    public function __construct(string $id, string $label, string $description)
    {
        $this->id = Capability::fromString($id)->value();
        $label = trim($label);
        $description = trim($description);
        if ($label === '' || mb_strlen($label) > 100) {
            throw new InvalidArgumentException('A contributed capability label must contain 1 to 100 characters.');
        }
        if ($description === '' || mb_strlen($description) > 500) {
            throw new InvalidArgumentException(
                'A contributed capability description must contain 1 to 500 characters.',
            );
        }
        $this->label = $label;
        $this->description = $description;
    }

    public function identifier(): string
    {
        return $this->id;
    }

    /** @return array{id: string, label: string, description: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'description' => $this->description];
    }
}
