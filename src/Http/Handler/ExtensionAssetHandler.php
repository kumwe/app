<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Handler;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ExtensionAssetHandler implements RequestHandlerInterface
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private ClockInterface $clock,
        private StreamFactoryInterface $streams,
        private string $assetRoot,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getAttribute('path');
        if (!is_string($path) || preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*/[0-9A-Za-z.+-]+/[A-Za-z0-9][A-Za-z0-9._/-]*$#D', $path) !== 1
            || str_contains($path, '..')) {
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
        if (!is_string($root) || !is_string($resolved) || !str_starts_with($resolved, $root . '/')
            || !is_file($resolved) || is_link($file)) {
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
