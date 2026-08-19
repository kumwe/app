<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application\Custom;

/**
 * Bounded result returned by an extension-specific business view handler.
 *
 * @since  2.0.0
 */
final readonly class CustomBusinessViewResult
{
    /**
     * Result fields validated structurally at construction and against the signed schema by the registry.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $data;

    /**
     * Admit one result map before it can cross back into a delivery adapter.
     *
     * @param   array<string, mixed>  $data  Contract-shaped fields, including any bounded rows or cursor.
     *
     * @throws  \InvalidArgumentException  When the result is not bounded exact JSON data.
     *
     * @since   2.0.0
     */
    public function __construct(array $data)
    {
        CustomBusinessPayload::assertObject($data, 'view result');
        $this->data = $data;
    }
}
