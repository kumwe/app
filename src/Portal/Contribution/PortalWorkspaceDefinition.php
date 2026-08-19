<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\ContributionDefinition;

/**
 * Bounded declaration of one navigation group in the ordinary-user portal shell.
 *
 * @since  2.0.0
 */
final readonly class PortalWorkspaceDefinition implements ContributionDefinition
{
    /**
     * Validate a portal workspace declaration.
     *
     * @param   string  $id           Dotted owner-scoped identifier.
     * @param   string  $label        Visible heading, 1 through 80 characters.
     * @param   string  $description  Accessible explanation, 1 through 255 characters.
     * @param   int     $priority     Stable sort weight from 0 through 100000.
     *
     * @throws  InvalidArgumentException  When any value falls outside its bound.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $description,
        public int $priority,
    ) {
        self::assertIdentifier($id, 'workspace');
        if (trim($label) === '' || mb_strlen($label) > 80) {
            throw new InvalidArgumentException('A portal workspace label must contain 1 to 80 characters.');
        }
        if (trim($description) === '' || mb_strlen($description) > 255) {
            throw new InvalidArgumentException('A portal workspace description must contain 1 to 255 characters.');
        }
        if ($priority < 0 || $priority > 100_000) {
            throw new InvalidArgumentException('A portal workspace priority is invalid.');
        }
    }

    /**
     * Enforce the bounded extension-compatible contribution grammar, including legacy internal dots.
     *
     * @param   string  $identifier  Candidate dotted identifier.
     * @param   string  $kind        Contribution kind named in a rejection.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When malformed.
     *
     * @since   2.0.0
     */
    public static function assertIdentifier(string $identifier, string $kind): void
    {
        if (
            strlen($identifier) > 191
            || preg_match('/^[a-z0-9][a-z0-9._-]*\.[a-z0-9._-]*[a-z0-9]$/D', $identifier) !== 1
        ) {
            throw new InvalidArgumentException(sprintf('A contributed portal %s identifier is invalid.', $kind));
        }
    }

    /**
     * Return the claimed identifier.
     *
     * @return  string  Dotted workspace identifier.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->id;
    }

    /**
     * Export a deterministic manifest-comparison shape.
     *
     * @return  array{id: string, label: string, description: string, priority: int}  Declaration fields.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'priority' => $this->priority,
        ];
    }
}
