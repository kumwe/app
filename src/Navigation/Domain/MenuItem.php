<?php

declare(strict_types=1);

namespace Kumwe\CMS\Navigation\Domain;

use InvalidArgumentException;

/**
 * One entry of a menu, validated the moment it comes into existence.
 *
 * The constructor is private, so an item can only exist in a shape the navigation rules accept: a
 * canonical UUID, a trimmed title of 1 to 255 characters, and a slug that is already a safe URL
 * segment. Items are born without a path, because placement belongs to `MenuTree`: the tree derives
 * the path from the parent chain and hands back a repositioned copy through `placedAt()`. Nothing
 * outside the tree can therefore give an item a path its ancestry does not justify.
 *
 * @since  2.0.0
 */
final readonly class MenuItem
{
    /**
     * Builds a validated item; every rule the navigation model leans on is checked here.
     *
     * @param   string   $id        Canonical UUID identifying the item.
     * @param   string   $title     Display label, already trimmed by the caller.
     * @param   string   $slug      URL segment this item contributes to its own path.
     * @param   ?string  $parentId  UUID of the item this one hangs beneath, or null at the root.
     * @param   string   $path      Absolute path derived from the parent chain, or an empty string for an
     *          item that has not been placed yet.
     *
     * @throws  InvalidArgumentException  When an identifier is not a canonical UUID, the title is not 1 to
     *          255 characters, the slug is not a hyphen-joined lowercase segment, or the path is not an
     *          absolute chain of such segments.
     *
     * @since   2.0.0
     */
    private function __construct(
        private string $id,
        private string $title,
        private string $slug,
        private ?string $parentId,
        private string $path,
    ) {
        self::assertUuid($id, 'menu item');

        if ($parentId !== null) {
            self::assertUuid($parentId, 'parent menu item');
        }

        $titleLength = mb_strlen(trim($title));

        if ($titleLength < 1 || $titleLength > 255) {
            throw new InvalidArgumentException('A menu item title must contain between 1 and 255 characters.');
        }

        if (
            mb_strlen($slug) > 160
            || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1
        ) {
            throw new InvalidArgumentException(
                'A menu item slug must contain lowercase ASCII letters, digits, and single hyphens.',
            );
        }

        if ($path !== '' && preg_match('#^/[a-z0-9]+(?:-[a-z0-9]+)*(?:/[a-z0-9]+(?:-[a-z0-9]+)*)*$#D', $path) !== 1) {
            throw new InvalidArgumentException('A menu item path must be an absolute path composed of valid slugs.');
        }
    }

    /**
     * Creates a fresh, still unplaced item from caller-supplied values.
     *
     * Identifiers are lowercased and the title trimmed here, so two callers spelling the same UUID in
     * different cases produce items a tree treats as one. The path is left empty because only a
     * `MenuTree` knows the ancestry that gives it a value.
     *
     * @param   string   $id        Canonical UUID to identify the item by; letter case is normalised.
     * @param   string   $title     Display label; surrounding whitespace is stripped.
     * @param   string   $slug      URL segment for this item, already lowercase and hyphen-joined.
     * @param   ?string  $parentId  UUID of the parent item, or null to place the item at the root.
     *
     * @return  self  An item whose path is still empty, ready to hand to `MenuTree::create()`.
     *
     * @throws  InvalidArgumentException  When an identifier is not a canonical UUID, the title is not 1 to
     *          255 characters, or the slug is not a valid URL segment.
     *
     * @since   2.0.0
     */
    public static function create(string $id, string $title, string $slug, ?string $parentId = null): self
    {
        return new self(strtolower($id), trim($title), $slug, $parentId === null ? null : strtolower($parentId), '');
    }

    /**
     * Returns the identifier the rest of the navigation model addresses this item by.
     *
     * @return  string  Canonical UUID, lowercased when the item was created.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Returns the label a visitor sees for this entry.
     *
     * @return  string  Trimmed display text of 1 to 255 characters.
     *
     * @since   2.0.0
     */
    public function title(): string
    {
        return $this->title;
    }

    /**
     * Returns the URL segment this item contributes to its own path and to every descendant path.
     *
     * @return  string  Lowercase letters and digits joined by single hyphens, at most 160 characters.
     *
     * @since   2.0.0
     */
    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * Returns the item this one hangs beneath.
     *
     * @return  ?string  UUID of the parent, or null when the item sits at the root of its menu.
     *
     * @since   2.0.0
     */
    public function parentId(): ?string
    {
        return $this->parentId;
    }

    /**
     * Returns the absolute path this item resolves to inside its menu.
     *
     * @return  string  Leading-slash path built from the slug chain, or an empty string while the item
     *          has not been placed in a tree.
     *
     * @since   2.0.0
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns a copy of this item hung under a new parent, carrying the path that placement produces.
     *
     * `MenuTree` is the intended caller: it supplies the parent and the derived path together, so an item
     * can never end up with a path that contradicts its ancestry. The receiver is left untouched.
     *
     * @param   ?string  $parentId  UUID of the new parent, or null to place the item at the root.
     * @param   string   $path      Absolute path the tree computed from the new parent chain.
     *
     * @return  self  A new item; the one it was called on is unchanged.
     *
     * @throws  InvalidArgumentException  When the parent id is not a canonical UUID, or the path is not an
     *          absolute chain of valid slugs.
     *
     * @since   2.0.0
     */
    public function placedAt(?string $parentId, string $path): self
    {
        return new self($this->id, $this->title, $this->slug, $parentId, $path);
    }

    /**
     * Rejects an identifier that is not a canonical UUID.
     *
     * @param   string  $id       Candidate identifier as handed to the constructor.
     * @param   string  $subject  Noun naming what the identifier addresses, quoted back in the failure
     *          message as `menu item` or `parent menu item`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a canonical UUID of version 1 through 8.
     *
     * @since   2.0.0
     */
    private static function assertUuid(string $id, string $subject): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException(sprintf('A %s ID must be a canonical UUID.', $subject));
        }
    }
}
