<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class BlockSchema
{
    /**
     * @param array<string, BlockPropertyType> $properties
     * @param list<string>                     $requiredProperties
     * @param list<string>                     $allowedChildTypes
     */
    public function __construct(
        private string $type,
        private array $properties,
        private array $requiredProperties = [],
        private array $allowedChildTypes = [],
        private bool $allowsChildren = false,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $type) !== 1) {
            throw new InvalidArgumentException('A block schema type must be a stable lowercase identifier.');
        }

        foreach ($properties as $name => $propertyType) {
            if (!is_string($name) || !$propertyType instanceof BlockPropertyType) {
                throw new InvalidArgumentException('Block property schemas must map names to property types.');
            }
        }

        if (!array_is_list($requiredProperties) || !array_is_list($allowedChildTypes)) {
            throw new InvalidArgumentException('Required properties and allowed child types must be ordered lists.');
        }

        foreach ($requiredProperties as $required) {
            if (!array_key_exists($required, $properties)) {
                throw new InvalidArgumentException(sprintf('Required property %s has no schema.', $required));
            }
        }

        if (count($requiredProperties) !== count(array_unique($requiredProperties))) {
            throw new InvalidArgumentException('Required block properties must be unique.');
        }

        foreach ($allowedChildTypes as $childType) {
            if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $childType) !== 1) {
                throw new InvalidArgumentException('Allowed child block types must be stable identifiers.');
            }
        }
    }

    public function type(): string
    {
        return $this->type;
    }

    public function validate(BlockNode $node): void
    {
        if ($node->type() !== $this->type) {
            throw new InvalidBlockDocument(sprintf('Expected block type %s, received %s.', $this->type, $node->type()));
        }

        foreach ($node->properties() as $name => $value) {
            if (!isset($this->properties[$name])) {
                throw new InvalidBlockDocument(sprintf(
                    'Property %s is not declared for block %s.',
                    $name,
                    $this->type,
                ));
            }

            if (!$this->properties[$name]->accepts($value)) {
                throw new InvalidBlockDocument(sprintf('Property %s has the wrong type.', $name));
            }
        }

        foreach ($this->requiredProperties as $required) {
            if (!array_key_exists($required, $node->properties())) {
                throw new InvalidBlockDocument(sprintf('Required property %s is missing.', $required));
            }
        }

        if (!$this->allowsChildren && $node->children() !== []) {
            throw new InvalidBlockDocument(sprintf('Block %s cannot contain child blocks.', $this->type));
        }

        foreach ($node->children() as $child) {
            if ($this->allowedChildTypes !== [] && !in_array($child->type(), $this->allowedChildTypes, true)) {
                throw new InvalidBlockDocument(sprintf(
                    'Block %s cannot contain child block %s.',
                    $this->type,
                    $child->type(),
                ));
            }
        }
    }
}
