<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Development;

use FilesystemIterator;
use InvalidArgumentException;
use Kumwe\App\Extension\Domain\ExtensionManifest;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Publishes a complete extension source tree from the shipped, bounded template.
 *
 * Files are written to a private sibling directory, validated as a whole, and renamed into place only
 * after every token has been resolved and the generated manifest passes the production parser.
 *
 * @since  2.0.0
 */
final class ComponentScaffolder
{
    /**
     * Most template files one scaffold may contain.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_FILES = 256;

    /**
     * Largest individual text template accepted by the generator.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_FILE_BYTES = 1_048_576;

    /**
     * Root holding the immutable complete-component template.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $templateRoot;

    /**
     * Select the shipped template or an explicit fixture root used by tests.
     *
     * @param  ?string  $templateRoot  Absolute template root; null selects the shipped component template.
     *
     * @since  2.0.0
     */
    public function __construct(?string $templateRoot = null)
    {
        $this->templateRoot = $templateRoot
            ?? dirname(__DIR__, 3) . '/resources/extension-scaffold/complete-component';
    }

    /**
     * Generate and atomically publish one extension source tree.
     *
     * Existing targets are never merged into or replaced. A failed generation removes only the private
     * sibling directory allocated by this invocation, leaving the requested target absent.
     *
     * @param   ScaffoldRequest  $request  Validated package identity and target information.
     *
     * @return  ScaffoldResult  Published path and generated file count.
     *
     * @throws  InvalidArgumentException  When template or target paths are unsafe.
     * @throws  RuntimeException  When a template cannot be read, written, validated, or published atomically.
     *
     * @since   2.0.0
     */
    public function scaffold(ScaffoldRequest $request): ScaffoldResult
    {
        $templateRoot = realpath($this->templateRoot);
        if (!is_string($templateRoot) || !is_dir($templateRoot) || is_link($this->templateRoot)) {
            throw new InvalidArgumentException('The extension scaffold template root is unavailable or unsafe.');
        }
        if (file_exists($request->targetDirectory) || is_link($request->targetDirectory)) {
            throw new InvalidArgumentException('The extension scaffold target already exists.');
        }
        $parent = realpath(dirname($request->targetDirectory));
        if (!is_string($parent) || !is_dir($parent) || is_link(dirname($request->targetDirectory))) {
            throw new InvalidArgumentException('The extension scaffold target parent is unavailable or unsafe.');
        }
        $target = $parent . '/' . basename($request->targetDirectory);
        if ($target !== rtrim($request->targetDirectory, '/')) {
            throw new InvalidArgumentException('The extension scaffold target must use a canonical absolute path.');
        }
        if (!is_writable($parent)) {
            throw new RuntimeException('The extension scaffold target parent is not writable.');
        }

        $temporary = $parent . '/.' . basename($target) . '.kumwe-scaffold-' . bin2hex(random_bytes(12));
        if (!mkdir($temporary, 0700)) {
            throw new RuntimeException('The private extension scaffold directory could not be created.');
        }

        try {
            $files = $this->templateFiles($templateRoot);
            $tokens = $this->tokens($request);
            foreach ($files as $relative => $source) {
                $destinationRelative = str_ends_with($relative, '.tpl')
                    ? substr($relative, 0, -4)
                    : $relative;
                $destination = $temporary . '/' . $destinationRelative;
                $directory = dirname($destination);
                if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
                    throw new RuntimeException('A scaffold output directory could not be created.');
                }
                $contents = $this->readTemplate($source);
                $rendered = str_replace(array_keys($tokens), array_values($tokens), $contents);
                $this->assertResolved($rendered, $destinationRelative);
                if (file_put_contents($destination, $rendered, LOCK_EX) !== strlen($rendered)) {
                    throw new RuntimeException('A scaffold output file could not be written completely.');
                }
                if (!chmod($destination, 0644)) {
                    throw new RuntimeException('A scaffold output file could not be protected.');
                }
            }
            $manifestJson = file_get_contents($temporary . '/kumwe.json');
            if (!is_string($manifestJson)) {
                throw new RuntimeException('The generated extension manifest could not be read.');
            }
            ExtensionManifest::fromJson($manifestJson);
            if (!rename($temporary, $target)) {
                throw new RuntimeException('The completed extension scaffold could not be published atomically.');
            }

            return new ScaffoldResult($target, count($files));
        } catch (Throwable $failure) {
            $this->removePrivateTree($temporary, $parent);
            throw $failure;
        }
    }

    /**
     * Enumerate regular templates in deterministic relative-path order.
     *
     * @param   string  $root  Canonical template root.
     *
     * @return  array<string, string>  Source paths keyed by relative path, sorted bytewise.
     *
     * @throws  RuntimeException  When the tree is empty, oversized, linked, or contains a special file.
     *
     * @since   2.0.0
     */
    private function templateFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            if ($file->isLink() || !$file->isFile()) {
                throw new RuntimeException('The extension scaffold template contains a link or special file.');
            }
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($relative === '' || str_contains($relative, '\\')) {
                throw new RuntimeException('The extension scaffold template contains an unsafe path.');
            }
            $files[$relative] = $file->getPathname();
            if (count($files) > self::MAXIMUM_FILES) {
                throw new RuntimeException('The extension scaffold template contains too many files.');
            }
        }
        if ($files === [] || !isset($files['kumwe.json.tpl'])) {
            throw new RuntimeException('The extension scaffold template must contain kumwe.json.tpl.');
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * Read one bounded regular template without following a link.
     *
     * @param   string  $path  Absolute template file path.
     *
     * @return  string  Complete template bytes.
     *
     * @throws  RuntimeException  When the file changed type, exceeds its bound, or cannot be read.
     *
     * @since   2.0.0
     */
    private function readTemplate(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('An extension scaffold template file is unsafe.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size > self::MAXIMUM_FILE_BYTES) {
            throw new RuntimeException('An extension scaffold template file exceeds its size bound.');
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || strlen($contents) !== $size) {
            throw new RuntimeException('An extension scaffold template file could not be read completely.');
        }

        return $contents;
    }

    /**
     * Build the complete replacement map for the shipped template.
     *
     * @param   ScaffoldRequest  $request  Validated scaffold input.
     *
     * @return  array<string, string>  Template tokens mapped to final values.
     *
     * @since   2.0.0
     */
    private function tokens(ScaffoldRequest $request): array
    {
        $identifier = $request->identifier->value();
        $namespaceJson = json_encode($request->phpNamespace, JSON_THROW_ON_ERROR);
        $labelJson = json_encode($request->label, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            '@@EXTENSION_IDENTIFIER@@' => $identifier,
            '@@EXTENSION_DOTTED@@' => $request->contributionNamespace(),
            '@@PHP_NAMESPACE@@' => $request->phpNamespace,
            '@@PHP_NAMESPACE_JSON@@' => substr($namespaceJson, 1, -1),
            '@@LABEL@@' => $request->label,
            '@@LABEL_JSON@@' => substr($labelJson, 1, -1),
            '@@LABEL_PHP@@' => addcslashes($request->label, "\\'"),
            '@@VERSION@@' => (string) $request->version,
            '@@ENTITY_ID@@' => Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                'https://kumwe.dev/extensions/' . $identifier . '/entity/item',
            )->toString(),
        ];
    }

    /**
     * Refuse generated text that still contains a scaffold marker or unfinished-work marker.
     *
     * @param   string  $contents  Rendered file contents.
     * @param   string  $path      Relative path used in an operator-facing failure.
     *
     * @return  void
     *
     * @throws  RuntimeException  When a marker remains.
     *
     * @since   2.0.0
     */
    private function assertResolved(string $contents, string $path): void
    {
        if (
            preg_match('/@@[A-Z0-9_]+@@|\{\{[A-Z0-9_]+\}\}/D', $contents) === 1
            || preg_match('/\b(?:TODO|FIXME)\b/', $contents) === 1
        ) {
            throw new RuntimeException(sprintf('The generated scaffold file %s is incomplete.', $path));
        }
    }

    /**
     * Remove only the private sibling directory allocated by this scaffolding invocation.
     *
     * @param   string  $temporary  Exact private directory to remove.
     * @param   string  $parent     Canonical parent the private directory must remain under.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function removePrivateTree(string $temporary, string $parent): void
    {
        if (!str_starts_with($temporary, $parent . '/.') || !is_dir($temporary) || is_link($temporary)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($temporary);
    }
}
