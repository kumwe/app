<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Development;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\SemanticVersion;

/**
 * Validated input for creating one complete extension source tree.
 *
 * The target stays as an absolute path so scaffolding never depends on the process working directory.
 * Package identity, PHP namespace, label, and version are proven here before a template is read.
 *
 * @since  2.0.0
 */
final readonly class ScaffoldRequest
{
    /**
     * Validated package identity used in the generated manifest.
     *
     * @var    ExtensionIdentifier
     * @since  2.0.0
     */
    public ExtensionIdentifier $identifier;

    /**
     * Validated release version used in the generated manifest.
     *
     * @var    SemanticVersion
     * @since  2.0.0
     */
    public SemanticVersion $version;

    /**
     * Human-readable component label after surrounding whitespace is removed.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $label;

    /**
     * Validate values before the scaffolder is allowed to touch the filesystem.
     *
     * @param   string  $identifier       Canonical `vendor/name` package identifier.
     * @param   string  $phpNamespace     Root PHP namespace without a trailing separator.
     * @param   string  $targetDirectory  Absolute path that must not already exist.
     * @param   string  $label            Human-readable component label.
     * @param   string  $version          Semantic Versioning release written to the manifest.
     *
     * @throws  InvalidArgumentException  When a namespace, path, label, or version is malformed.
     *
     * @since   2.0.0
     */
    public function __construct(
        string $identifier,
        public string $phpNamespace,
        public string $targetDirectory,
        string $label,
        string $version = '1.0.0',
    ) {
        $this->identifier = ExtensionIdentifier::fromString($identifier);
        $this->version = SemanticVersion::fromString($version);
        if (
            strlen($phpNamespace) > 255
            || preg_match('/^[A-Z][A-Za-z0-9_]*(?:\\\\[A-Z][A-Za-z0-9_]*)+$/D', $phpNamespace) !== 1
        ) {
            throw new InvalidArgumentException(
                'The extension PHP namespace must contain at least two PascalCase segments.',
            );
        }
        if (!str_starts_with($targetDirectory, '/') || str_contains($targetDirectory, "\0")) {
            throw new InvalidArgumentException('The extension scaffold target must be an absolute path.');
        }
        if (in_array(basename($targetDirectory), ['', '.', '..'], true)) {
            throw new InvalidArgumentException('The extension scaffold target path is invalid.');
        }
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 80 || preg_match('/[\p{Cc}\p{Cf}]/u', $label) !== 0) {
            throw new InvalidArgumentException(
                'The extension label must contain 1 to 80 printable characters.',
            );
        }
        $this->label = $label;
    }

    /**
     * Return the dotted namespace every owned contribution identifier begins with.
     *
     * @return  string  Package identifier with its vendor separator changed to a dot.
     *
     * @since   2.0.0
     */
    public function contributionNamespace(): string
    {
        return str_replace('/', '.', $this->identifier->value());
    }
}
