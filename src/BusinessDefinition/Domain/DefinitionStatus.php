<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * Lifecycle state of a business definition, from the editable draft to a withdrawn version.
 *
 * Publication is the hinge. `Draft` describes the version-zero working copy an author saves against an
 * optimistic revision, and every other case describes a published version whose canonical bytes and
 * SHA-256 checksum are already immutable. That is why the later states are kept in a column beside the
 * version row rather than inside the payload: moving a version through them must never rewrite the bytes
 * the checksum was taken over. The transitions are correspondingly narrow — a draft may only be published
 * to a positive version, publication supersedes the version before it, and an explicit status change
 * accepts nothing but `Superseded`, `Deprecated`, and `Rejected`.
 *
 * @since  2.0.0
 */
enum DefinitionStatus: string
{
    /**
     * The editable working copy, pinned to definition version zero and never stored as a version row.
     *
     * A draft is the one state an author writes into directly, and it is left by publishing rather than
     * by a status change; nothing brings a published definition back to it.
     *
     * @since  2.0.0
     */
    case Draft = 'draft';
    /**
     * A version whose canonical payload, checksum, dependency graph, and compatibility plan are recorded.
     *
     * It stays the definition's head until a successor is published, at which point it is superseded.
     *
     * @since  2.0.0
     */
    case Published = 'published';
    /**
     * Displaced by a newer published version, and kept intact for history, audit, and restore.
     *
     * Existing records built on it keep working; superseding says a newer contract exists, not that this
     * one has been withdrawn.
     *
     * @since  2.0.0
     */
    case Superseded = 'superseded';
    /**
     * Still on record and still serviceable, but no longer something to build on.
     *
     * Package synchronization deprecates the last published version of a definition an extension has
     * stopped declaring, which is how a removed declaration keeps its history rather than being erased.
     *
     * @since  2.0.0
     */
    case Deprecated = 'deprecated';
    /**
     * Withdrawn: the version stays on record, but the record runtime refuses to serve it.
     *
     * Definition resolution treats a rejected version as a schema that has gone away and fails rather
     * than reading records against it, so this is the state that actually takes a contract out of use.
     *
     * @since  2.0.0
     */
    case Rejected = 'rejected';
}
