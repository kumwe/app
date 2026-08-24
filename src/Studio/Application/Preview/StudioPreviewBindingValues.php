<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use JsonException;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use RuntimeException;
use stdClass;

/**
 * Canonical trusted values available while resolving one preview's Blueprint bindings.
 *
 * Values originate from App authority through the read-only Content projection. They are copied as
 * canonical JSON so a renderer cannot mutate an authoritative projection or retain its object identity.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewBindingValues
{
    /**
     * Canonical projected Content entry values.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $entryCanonical;

    /**
     * Canonical host context values exposed under explicitly registered keys.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $contextCanonical;

    /**
     * Copy the two trusted value namespaces before rendering starts.
     *
     * @param  stdClass  $entryValues    Authorized Studio entry `values`, empty for a Blueprint-only preview.
     * @param  stdClass  $contextValues  Host-owned context values, empty until a key is explicitly registered.
     *
     * @since  2.0.0
     */
    public function __construct(stdClass $entryValues, stdClass $contextValues)
    {
        $this->entryCanonical = CanonicalJson::stringify($entryValues);
        $this->contextCanonical = CanonicalJson::stringify($contextValues);
    }

    /**
     * Return a fresh copy of authorized entry values.
     *
     * @return  stdClass  Projected Content values keyed by Studio field identifier.
     *
     * @since   2.0.0
     */
    public function entry(): stdClass
    {
        return self::decode($this->entryCanonical);
    }

    /**
     * Return a fresh copy of registered host context values.
     *
     * @return  stdClass  Values keyed by canonical context identifier.
     *
     * @since   2.0.0
     */
    public function context(): stdClass
    {
        return self::decode($this->contextCanonical);
    }

    /**
     * Decode one in-memory canonical object and fail if impossible corruption occurred.
     *
     * @param   string  $canonical  Canonical object bytes retained by this value object.
     *
     * @return  stdClass  Fresh decoded object.
     *
     * @throws  RuntimeException  When the retained bytes are no longer readable as an object.
     *
     * @since   2.0.0
     */
    private static function decode(string $canonical): stdClass
    {
        try {
            $decoded = json_decode($canonical, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The trusted Studio preview binding values are unreadable.', 0, $exception);
        }
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('The trusted Studio preview binding values are not an object.');
        }

        return $decoded;
    }
}
