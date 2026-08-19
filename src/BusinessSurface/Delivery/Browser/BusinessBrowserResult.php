<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Delivery\Browser;

use InvalidArgumentException;

/**
 * Transport-neutral browser controller outcome rendered by administrator or portal adapters.
 *
 * @since  2.0.0
 */
final readonly class BusinessBrowserResult
{
    /**
     * Capture a rendered page or a post/redirect/get destination.
     *
     * @param   ?string               $template  Core business template name, null for a redirect.
     * @param   array<string, mixed>  $data      Safe shared service result for Twig.
     * @param   ?string               $redirect  Absolute same-origin destination, null for a page.
     * @param   int                   $status    HTTP status for a page or redirect.
     *
     * @throws  InvalidArgumentException  When both or neither template and redirect are supplied.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ?string $template,
        public array $data = [],
        public ?string $redirect = null,
        public int $status = 200,
    ) {
        if (($template === null) === ($redirect === null)) {
            throw new InvalidArgumentException('A business browser result must render or redirect, not both.');
        }
        if ($status < 200 || $status > 599) {
            throw new InvalidArgumentException('A business browser result status is invalid.');
        }
    }

    /**
     * Build a successful post/redirect/get outcome.
     *
     * @param   string  $target  Same-origin absolute path.
     *
     * @return  self  303 redirect result.
     *
     * @since   2.0.0
     */
    public static function redirect(string $target): self
    {
        return new self(null, redirect: $target, status: 303);
    }
}
