<?php

declare(strict_types=1);

namespace Kumwe\App\Http\Handler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Streams a built file from an installed extension's asset directory, and only while it stays trusted.
 *
 * Extension build output is not published by the web server directly, because serving it is a policy
 * decision rather than a file lookup. Every single request re-asks the registry whether the owning
 * extension is still active, whether the installed release is still verified, and whether the signing
 * key behind that verification is enabled, unrevoked, and inside its validity window. Disabling an
 * extension or revoking its key therefore takes its assets offline on the next request, with no cache to
 * purge — which is also why the responses it does serve are marked private and `no-store`.
 *
 * The handler is equally the boundary that keeps the asset root sealed. A path must match the
 * `vendor/name/version/file` shape, must not contain `..`, must resolve inside the root, and must not
 * traverse a symbolic link at any segment, so neither a crafted URL nor a link planted in the build
 * output can read a file elsewhere on the host. Every refusal — bad shape, untrusted extension, missing
 * file — is the same bare 404, so probing teaches a caller nothing about what is installed.
 *
 * @since  2.0.0
 */
final readonly class ExtensionAssetHandler implements RequestHandlerInterface
{
    /**
     * Bind the route to the trust records it re-checks and the directory it may serve from.
     *
     * @param  Connection              $database   Connection the extension, release, and trust-key
     *         tables are read through.
     * @param  TableNames              $tables     Resolver for the deployment's prefixed table names.
     * @param  ClockInterface          $clock      Clock the signing key's validity window is judged
     *         against, so time is injected rather than read from the host.
     * @param  StreamFactoryInterface  $streams    Factory used to open the file as a response body.
     * @param  string                  $assetRoot  Absolute directory holding published extension build
     *         output; nothing outside it is ever served.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private StreamFactoryInterface $streams,
        private string $assetRoot,
    ) {
    }

    /**
     * Serves one extension asset, after re-checking the extension's trust and sealing the file path.
     *
     * The order matters: the path shape is validated, then trust is confirmed from the first three
     * segments, and only then is the file resolved on disk. A caller that fails the trust check never
     * reaches a filesystem call, so the route cannot be used to probe for files.
     *
     * @param   ServerRequestInterface  $request  Request whose `path` route attribute carries the
     *          `vendor/name/version/file` asset path.
     *
     * @return  ResponseInterface  A 200 stream with the mapped media type, `nosniff`, and a private
     *          no-store cache policy, or an identical bare 404 for every rejection.
     *
     * @throws  \Doctrine\DBAL\Exception  When the driver cannot execute the trust lookup.
     * @throws  \RuntimeException  When the resolved file passes every check but cannot be opened.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('path');
        $assetPattern = '#^[a-z0-9][a-z0-9._-]*/'
            . '[a-z0-9][a-z0-9._-]*/'
            . '[0-9A-Za-z.+-]+/'
            . '[A-Za-z0-9][A-Za-z0-9._/-]*$#D';
        if (
            !is_string($path) || preg_match($assetPattern, $path) !== 1
            || str_contains($path, '..')
        ) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }
        $segments = explode('/', $path);
        $runtime = implode('/', array_slice($segments, 0, 3));
        $authorized = $this->database->fetchOne(sprintf(
            'SELECT e.id FROM %s e INNER JOIN %s r ON r.extension_id = e.id AND r.version = e.installed_version '
            . 'LEFT JOIN %s k ON k.key_id = r.signing_key_id '
            . "WHERE e.runtime_path = ? AND e.status = 'active' AND r.trust_state = 'verified' "
            . 'AND (r.signing_key_id IS NULL OR (k.enabled = ? AND k.revoked_at IS NULL '
            . 'AND k.not_before <= ? AND (k.expires_at IS NULL OR k.expires_at > ?)))',
            $this->tables->quoted('extensions'),
            $this->tables->quoted('extension_releases'),
            $this->tables->quoted('extension_trust_keys'),
        ), [$runtime, true, $this->clock->now(), $this->clock->now()], [
            Types::STRING, Types::BOOLEAN, Types::DATETIME_IMMUTABLE, Types::DATETIME_IMMUTABLE,
        ]);
        if (!is_string($authorized) || $authorized === '') {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }

        $root = realpath($this->assetRoot);
        $file = $this->assetRoot . '/' . $path;
        $resolved = realpath($file);
        if (
            !is_string($root) || !is_string($resolved) || !str_starts_with($resolved, $root . '/')
            || !is_file($resolved) || is_link($file)
        ) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }
        $candidate = $root;
        foreach (explode('/', $path) as $segment) {
            $candidate .= '/' . $segment;
            if (is_link($candidate)) {
                return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
            }
        }
        $mime = match (strtolower(pathinfo($resolved, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=utf-8',
            'js', 'mjs' => 'text/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };
        $size = filesize($resolved);
        if (!is_int($size)) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }

        return new Response($this->streams->createStreamFromFile($resolved, 'rb'), 200, [
            'Content-Type' => $mime,
            'Content-Length' => (string) $size,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
