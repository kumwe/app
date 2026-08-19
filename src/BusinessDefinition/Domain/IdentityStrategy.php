<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * How a business entity addresses its records, and which identity field its definition must carry.
 *
 * A definition declares exactly one strategy and must contain exactly one field of the matching type —
 * `core.uuid` or `core.reference_identity` — which `BusinessDefinitionValidator` then requires to be
 * required, non-null, unique, and immutable after creation. The choice reaches further than validation:
 * the schema compiler and `RecordValueCodec` read it to decide whether the identity a caller sees is
 * also the physical record key or a separate column beside a surrogate one.
 *
 * @since  2.0.0
 */
enum IdentityStrategy: string
{
    /**
     * Records are identified by a canonical UUID that doubles as the runtime record key.
     *
     * The identity field is not stored a second time: the compiler skips a column for it and the codec
     * fills the value back in from the record key when a row is read.
     *
     * @since  2.0.0
     */
    case Uuid = 'uuid';

    /**
     * Records are identified by a validated external reference held in a column of its own.
     *
     * The runtime still allocates a separate UUIDv7 record key on create, so a business reference can be
     * the operator-facing identity without becoming the key every relationship points at.
     *
     * @since  2.0.0
     */
    case Reference = 'reference';
}
