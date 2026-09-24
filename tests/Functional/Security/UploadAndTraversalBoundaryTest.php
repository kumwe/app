<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use Kumwe\App\Administrator\Http\Handler\AdministratorMediaHandler;
use Kumwe\App\Http\Handler\ExtensionAssetHandler;
use Kumwe\App\Http\Handler\MediaAssetHandler;
use Kumwe\App\Media\Application\MediaService;
use Kumwe\App\Media\Infrastructure\FilesystemMediaStorage;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Pins the upload and path-traversal boundaries of the live pipeline.
 *
 * An upload is judged by its bytes, never by the client's file name or declared media type, so a script, a
 * markup document or an SVG cannot be planted under an image name, and a polyglot that does pass byte
 * detection is stored under a server identifier with the detected extension and served with that detected
 * type and `nosniff`. The client's file name cannot steer where the bytes land. Every route that maps a URL
 * segment onto the filesystem answers an encoded or literal traversal attempt with the same bare refusal it
 * gives a missing file, and a traversal-shaped delete removes nothing outside the site's own library.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorMediaHandler::class)]
#[CoversClass(FilesystemMediaStorage::class)]
#[CoversClass(MediaAssetHandler::class)]
#[CoversClass(ExtensionAssetHandler::class)]
final class UploadAndTraversalBoundaryTest extends TestCase
{
    /**
     * Content-type confusion is refused by byte detection and a surviving polyglot is served inertly.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUploadsAreJudgedByTheirBytesAndNeverByTheClientNameOrType(): void
    {
        $harness = SecurityHttpHarness::boot();
        $cookie = $harness->administratorCookie();
        $csrf = $harness->administratorCsrf($cookie);
        $refused = [
            'script.jpg' => ["<?php echo 'owned';\n", 'image/jpeg'],
            'page.png' => ["<!doctype html><html><script>alert(1)</script></html>\n", 'image/png'],
            'logo.svg' => [
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>',
                'image/svg+xml',
            ],
            'archive.pdf' => ["PK\x03\x04" . str_repeat("\0", 26), 'application/pdf'],
        ];
        foreach ($refused as $name => [$bytes, $declared]) {
            $response = $this->upload($harness, $cookie, $csrf, $name, $bytes, $declared);
            self::assertSame(422, $response->getStatusCode(), $name . ' is refused by its bytes.');
        }

        $marker = bin2hex(random_bytes(6));
        $clientName = '../../../../public/polyglot-' . $marker . '.php';
        $polyglot = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,"
            . "\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;<?php echo 'owned'; ?>";
        $stored = $this->upload($harness, $cookie, $csrf, $clientName, $polyglot, 'application/x-php');
        self::assertSame(303, $stored->getStatusCode(), 'A polyglot that is a real image is accepted as an image.');
        $root = dirname(__DIR__, 3);
        self::assertFileDoesNotExist($root . '/public/polyglot-' . $marker . '.php');

        $library = (string) $harness->handle(
            $harness->request('GET', '/administrator/media?q=' . $marker)->withCookieParams($cookie),
        )->getBody();
        $listed = preg_match('#/media/([0-9a-f-]{36})/polyglot-' . $marker . '[^"\s]*#', $library, $link);
        self::assertSame(1, $listed, 'The asset is listed under its base name alone.');
        [$path, $id] = [$link[0], $link[1]];
        try {
            self::assertFileExists($root . '/storage/media/default/' . $id . '.gif');
            self::assertFileDoesNotExist($root . '/storage/media/default/' . $id . '.php');
            $served = $harness->handle($harness->request('GET', html_entity_decode($path)));
            self::assertSame(200, $served->getStatusCode());
            self::assertSame('image/gif', $served->getHeaderLine('Content-Type'), 'The detected type is served.');
            self::assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));
            self::assertStringNotContainsString('/', rawurldecode(
                (string) preg_replace('/^.*filename\*=UTF-8\'\'/', '', $served->getHeaderLine('Content-Disposition')),
            ), 'The served name carries no directory.');
        } finally {
            $harness->handle(
                $harness->request('POST', '/administrator/media/' . $id . '/delete')
                    ->withCookieParams($cookie)
                    ->withParsedBody(['_csrf' => $csrf]),
            );
        }
        self::assertFileDoesNotExist($root . '/storage/media/default/' . $id . '.gif');
    }

    /**
     * Every URL-to-file route refuses literal and encoded traversal exactly as it refuses a missing file.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTraversalAttemptsAreRefusedLikeMissingFilesAndDeleteNothing(): void
    {
        $harness = SecurityHttpHarness::boot();
        $probes = [
            '/media/..%2F..%2F..%2Fcomposer.json/composer.json',
            '/media/018f22e2-7c8b-7ab0-8f3a-88e8026bb999/..%2F..%2Fcomposer.json',
            '/media/%2e%2e/%2e%2e/composer.json',
            '/assets/extensions/..%2F..%2F..%2Fcomposer.json',
            '/assets/extensions/vendor/name/1.0.0/..%2F..%2F..%2F..%2Fcomposer.json',
            '/assets/extensions/vendor/name/1.0.0/%2e%2e/%2e%2e/%2e%2e/composer.json',
            '/assets/extensions/vendor/name/1.0.0/../../../../composer.json',
            '/studio/styles/..%2F..%2Fcomposer.css',
        ];
        foreach ($probes as $probe) {
            $response = $harness->handle($harness->request('GET', $probe));
            self::assertSame(404, $response->getStatusCode(), $probe);
            self::assertStringNotContainsString('"require"', (string) $response->getBody(), $probe);
        }

        $cookie = $harness->administratorCookie();
        $csrf = $harness->administratorCsrf($cookie);
        foreach (
            [
                '/administrator/reports/exports/..%2F..%2F..%2Fcomposer.json/download',
                '/administrator/reports/exports/018f22e2-7c8b-7ab0-8f3a-88e8026bb999/download',
            ] as $probe
        ) {
            $response = $harness->handle($harness->request('GET', $probe)->withCookieParams($cookie));
            self::assertSame(404, $response->getStatusCode(), $probe);
        }

        $root = dirname(__DIR__, 3);
        $foreign = $root . '/storage/media/security-foreign-' . bin2hex(random_bytes(4));
        $id = '018f22e2-7c8b-7ab0-8f3a-88e8026bb998';
        mkdir($foreign, 0o750, true);
        file_put_contents($foreign . '/' . $id . '.gif', 'GIF89a');
        file_put_contents($foreign . '/' . $id . '.json', json_encode([
            'name' => 'foreign.gif',
            'mime_type' => 'image/gif',
            'extension' => 'gif',
            'size' => 6,
            'created_at' => '2026-09-24T00:00:00+00:00',
        ], JSON_THROW_ON_ERROR));
        try {
            $delete = $harness->handle(
                $harness->request('POST', '/administrator/media/..%2F' . basename($foreign) . '%2F' . $id . '/delete')
                    ->withCookieParams($cookie)
                    ->withParsedBody(['_csrf' => $csrf]),
            );
            self::assertContains($delete->getStatusCode(), [303, 404, 405], 'The router never matches it.');
            $media = $harness->container->get(MediaService::class);
            self::assertInstanceOf(MediaService::class, $media);
            $media->delete(
                TestKernelFactory::administratorContext($harness->container),
                '../' . basename($foreign) . '/' . $id,
            );
            self::assertFileExists($foreign . '/' . $id . '.gif', 'A traversal-shaped delete removes nothing.');
            self::assertFileExists($foreign . '/' . $id . '.json');
        } finally {
            array_map('unlink', glob($foreign . '/*') ?: []);
            rmdir($foreign);
        }
    }

    /**
     * Submit one multipart upload to the administrator media library.
     *
     * @param   SecurityHttpHarness    $harness   Booted harness.
     * @param   array<string, string>  $cookie    Administrator session cookie.
     * @param   string                 $csrf      Session form token.
     * @param   string                 $name      Client file name.
     * @param   string                 $bytes     File contents.
     * @param   string                 $declared  Client-declared media type.
     *
     * @return  ResponseInterface  Pipeline response.
     *
     * @since   2.0.0
     */
    private function upload(
        SecurityHttpHarness $harness,
        array $cookie,
        string $csrf,
        string $name,
        string $bytes,
        string $declared,
    ): ResponseInterface {
        $file = new UploadedFile(
            (new StreamFactory())->createStream($bytes),
            strlen($bytes),
            UPLOAD_ERR_OK,
            $name,
            $declared,
        );

        return $harness->handle(
            $harness->request('POST', '/administrator/media')
                ->withCookieParams($cookie)
                ->withParsedBody(['_csrf' => $csrf])
                ->withUploadedFiles(['media' => $file]),
        );
    }
}
