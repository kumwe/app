<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Twig;

use Twig\Error\LoaderError;
use Twig\Loader\LoaderInterface;
use Twig\Source;

final readonly class ContractRestrictedLoader implements LoaderInterface
{
    /** @var array<string, true> */
    private array $allowed;

    /** @param list<string> $allowed */
    public function __construct(private LoaderInterface $loader, array $allowed)
    {
        $this->allowed = array_fill_keys($allowed, true);
    }

    public function getSourceContext(string $name): Source
    {
        $this->assertAllowed($name);

        return $this->loader->getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        $this->assertAllowed($name);

        return $this->loader->getCacheKey($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        $this->assertAllowed($name);

        return $this->loader->isFresh($name, $time);
    }

    public function exists(string $name): bool
    {
        return isset($this->allowed[$name]) && $this->loader->exists($name);
    }

    private function assertAllowed(string $name): void
    {
        if (!isset($this->allowed[$name])) {
            throw new LoaderError(sprintf('Administrator theme template %s is outside the override contract.', $name));
        }
    }
}
