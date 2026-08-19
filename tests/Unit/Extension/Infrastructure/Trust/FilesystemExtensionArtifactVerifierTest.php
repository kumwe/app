<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Infrastructure\Trust;

use Kumwe\App\Extension\Application\Trust\UntrustedPackage;
use Kumwe\App\Extension\Infrastructure\Trust\FilesystemExtensionArtifactVerifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilesystemExtensionArtifactVerifier::class)]
final class FilesystemExtensionArtifactVerifierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/kumwe-artifact-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/acme/catalog/1.0.0/src', 0700, true);
        file_put_contents($this->root . '/acme/catalog/1.0.0/src/Provider.php', '<?php return true;');
        file_put_contents($this->root . '/acme/catalog/1.0.0/.kumwe-package.zip', 'signed-package');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isLink() || !$item->isDir()
                ? unlink($item->getPathname())
                : rmdir($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testRejectsDeployedBytesChangedAfterSignatureVerification(): void
    {
        $runtime = 'acme/catalog/1.0.0';
        $directory = $this->root . '/' . $runtime;
        $release = [
            'runtime_path' => $runtime,
            'package_sha256' => hash('sha256', 'signed-package'),
            'artifact_sha256' => hash('sha256', 'signed-package'),
            'deployed_tree_sha256' => FilesystemExtensionArtifactVerifier::treeDigest($directory),
        ];
        $verifier = new FilesystemExtensionArtifactVerifier($this->root);
        $verifier->assertMatches($release);
        file_put_contents($directory . '/src/Provider.php', '<?php return false;');

        $this->expectException(UntrustedPackage::class);
        $verifier->assertMatches($release);
    }

    public function testRejectsSymlinksRatherThanSilentlyExcludingThemFromTheDigest(): void
    {
        $directory = $this->root . '/acme/catalog/1.0.0';
        if (!symlink($directory . '/src/Provider.php', $directory . '/src/Alias.php')) {
            self::markTestSkipped('Symbolic links are not available on this filesystem.');
        }

        $this->expectException(UntrustedPackage::class);
        FilesystemExtensionArtifactVerifier::treeDigest($directory);
    }

    public function testRejectsNonRegularFilesystemEntries(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('The POSIX extension is required for the non-regular entry test.');
        }
        $directory = $this->root . '/acme/catalog/1.0.0';
        posix_mkfifo($directory . '/src/pipe', 0600);

        $this->expectException(UntrustedPackage::class);
        FilesystemExtensionArtifactVerifier::treeDigest($directory);
    }
}
