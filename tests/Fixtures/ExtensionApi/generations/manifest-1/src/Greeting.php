<?php

declare(strict_types=1);

namespace KumweContract\ManifestOne;

/**
 * The manifest-1 compatibility package's own service, resolved through the restricted container.
 *
 * It has no host dependency on purpose: schema 1 promises a package may keep its own services in the
 * container it is handed, and nothing more.
 *
 * @since  2.0.0
 */
final class Greeting
{
    /**
     * Whether the provider's boot phase reached this service.
     *
     * @var    bool
     * @since  2.0.0
     */
    private bool $booted = false;

    /**
     * Record that the provider's boot phase resolved and used this service.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function boot(): void
    {
        $this->booted = true;
    }

    /**
     * Report whether the boot phase reached this service.
     *
     * @return  bool  True once `boot()` has run.
     *
     * @since   2.0.0
     */
    public function booted(): bool
    {
        return $this->booted;
    }
}
