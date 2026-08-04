<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Domain;

use InvalidArgumentException;

final readonly class TemplateOverridePolicy
{
    /** @param list<string> $allowedViews */
    public function __construct(private array $allowedViews)
    {
        if (!array_is_list($allowedViews)) {
            throw new InvalidArgumentException('Overrideable logical views must be an ordered list.');
        }

        if (count($allowedViews) !== count(array_unique($allowedViews))) {
            throw new InvalidArgumentException('Overrideable logical views must be unique.');
        }

        foreach ($allowedViews as $view) {
            if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/D', $view) !== 1) {
                throw new InvalidArgumentException('Overrideable logical views must be safe identifiers.');
            }
        }
    }

    public function authorize(string $logicalView, string $relativePath): string
    {
        if (!in_array($logicalView, $this->allowedViews, true)) {
            throw new InvalidArgumentException(sprintf('Template view %s is not overrideable.', $logicalView));
        }

        if (
            str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')
            || str_contains($relativePath, '//')
            || str_starts_with($relativePath, '/')
            || preg_match('#(^|/)\.\.(/|$)#D', $relativePath) === 1
            || preg_match('#(^|/)\.(/|$)#D', $relativePath) === 1
            || preg_match('#^[a-z][a-z0-9+.-]*:#iD', $relativePath) === 1
            || preg_match('#^[a-zA-Z0-9_./-]+\.twig$#D', $relativePath) !== 1
        ) {
            throw new InvalidArgumentException('A template override must be a safe relative Twig path.');
        }

        return $relativePath;
    }
}
