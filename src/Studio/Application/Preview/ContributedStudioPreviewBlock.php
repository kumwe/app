<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use InvalidArgumentException;
use JsonException;
use Kumwe\Extension\Spi\Studio\Application\Preview\StudioPreviewBlock;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use Kumwe\Producer\Canonical\CanonicalJson;
use RuntimeException;
use stdClass;

/**
 * Immutable copied block input exposed to an extension preview renderer.
 *
 * The host retains the admitted Blueprint object and owns slot traversal, binding evaluation and markup.
 * A contributed renderer receives only exact identity coordinates plus a canonical copy of bounded block
 * properties, so it cannot mutate the draft or reach arbitrary document members through the SPI.
 *
 * @since  2.0.0
 */
final readonly class ContributedStudioPreviewBlock implements StudioPreviewBlock
{
    /**
     * Canonical copied property object.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $propertiesCanonical;

    /**
     * Copy the exact node coordinates and its already schema-bounded properties.
     *
     * @param   string                $id          Stable Blueprint node identifier.
     * @param   string                $type        Qualified block type.
     * @param   string                $version     Exact block version.
     * @param   array<string, mixed>  $properties  Admitted block properties copied by canonical JSON.
     *
     * @throws  InvalidArgumentException  When a coordinate is empty or properties leave canonical JSON.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $id,
        private string $type,
        private string $version,
        array $properties,
    ) {
        if ($id === '' || $type === '' || $version === '') {
            throw new InvalidArgumentException('A Studio preview block coordinate is incomplete.');
        }
        try {
            $this->propertiesCanonical = CanonicalJson::stringify((object) $properties);
        } catch (CanonicalEncodingException $rejection) {
            throw new InvalidArgumentException(
                'Studio preview block properties must stay inside canonical JSON.',
                0,
                $rejection,
            );
        }
    }

    /**
     * Return the stable Blueprint node identifier.
     *
     * @return  string  Copied node identity.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return the qualified block type.
     *
     * @return  string  Copied block type coordinate.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Return the exact block version.
     *
     * @return  string  Copied block version coordinate.
     *
     * @since   2.0.0
     */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * Read one copied property without exposing the retained canonical object.
     *
     * @param   string  $name  Property name declared by the block definition.
     *
     * @return  mixed  Freshly decoded JSON value, or null when the property is absent.
     *
     * @throws  InvalidArgumentException  When the requested name is empty.
     * @throws  RuntimeException  When impossible corruption makes the retained copy unreadable.
     *
     * @since   2.0.0
     */
    public function property(string $name): mixed
    {
        if ($name === '') {
            throw new InvalidArgumentException('A Studio preview block property name is required.');
        }
        try {
            $properties = json_decode($this->propertiesCanonical, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The copied Studio preview block properties are unreadable.', 0, $exception);
        }
        if (!$properties instanceof stdClass) {
            throw new RuntimeException('The copied Studio preview block properties are not an object.');
        }

        return $properties->{$name} ?? null;
    }
}
