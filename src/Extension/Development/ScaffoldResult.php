<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Development;

/**
 * Immutable summary of a source tree published by the scaffolder.
 *
 * @since  2.0.0
 */
final readonly class ScaffoldResult
{
    /**
     * Record the published tree and the number of generated regular files.
     *
     * @param  string  $directory  Absolute path of the completed component tree.
     * @param  int     $fileCount  Number of files generated from the selected template.
     *
     * @since  2.0.0
     */
    public function __construct(public string $directory, public int $fileCount)
    {
    }

    /**
     * Export the result for console and SDK consumers.
     *
     * @return  array{directory: string, file_count: int}  Stable scaffold result.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['directory' => $this->directory, 'file_count' => $this->fileCount];
    }
}
