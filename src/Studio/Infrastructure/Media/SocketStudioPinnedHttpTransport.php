<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Media;

use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpResponse;
use Kumwe\App\Studio\Application\Media\StudioPinnedHttpTransport;

/**
 * Native TLS socket transport that connects directly to one classified address and never redirects.
 *
 * The TLS peer name and HTTP Host header remain the normalized URL hostname while the socket endpoint is
 * the DNS answer the caller pinned. Header, decoded-body and time quotas are applied while streaming;
 * compressed encodings are left declared for the higher boundary to reject without decompression.
 *
 * @since  2.0.0
 */
final readonly class SocketStudioPinnedHttpTransport implements StudioPinnedHttpTransport
{
    /**
     * Bind downloaded response bodies to a private directory.
     *
     * @param  string  $temporaryRoot  Absolute private download root.
     *
     * @since  2.0.0
     */
    public function __construct(private string $temporaryRoot)
    {
    }

    /**
     * Fetch one accepted URL by connecting directly to the supplied public address.
     *
     * @param   string  $url             Normalized HTTPS URL.
     * @param   string  $pinnedAddress   Classified public address.
     * @param   int     $maximumBytes    Inclusive decoded body quota.
     * @param   int     $timeoutSeconds  Per-hop connect and read deadline.
     *
     * @return  StudioPinnedHttpResponse  Bounded private response.
     *
     * @since   2.0.0
     */
    public function get(
        string $url,
        string $pinnedAddress,
        int $maximumBytes,
        int $timeoutSeconds,
    ): StudioPinnedHttpResponse {
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;
        $port = is_array($parts) ? ($parts['port'] ?? 443) : null;
        if (!is_string($host) || !is_int($port) || $port < 1 || $port > 65535) {
            throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-url-refused');
        }
        $peerName = trim($host, '[]');
        $address = str_contains($pinnedAddress, ':') ? '[' . $pinnedAddress . ']' : $pinnedAddress;
        $context = stream_context_create(['ssl' => [
            'SNI_enabled' => true,
            'peer_name' => $peerName,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'disable_compression' => true,
        ]]);
        $socket = @stream_socket_client(
            sprintf('tls://%s:%d', $address, $port),
            $errorCode,
            $errorMessage,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if (!is_resource($socket)) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
        }
        stream_set_timeout($socket, $timeoutSeconds);
        $deadline = microtime(true) + $timeoutSeconds;
        try {
            $target = self::target($parts);
            $authority = $peerName . ($port === 443 ? '' : ':' . $port);
            self::send($socket, "GET {$target} HTTP/1.1\r\n"
                . "Host: {$authority}\r\n"
                . "Accept: image/jpeg,image/png,image/gif,image/webp,image/avif,application/pdf\r\n"
                . "Accept-Encoding: identity\r\n"
                . "Connection: close\r\n"
                . "User-Agent: Kumwe-Studio-Media/2\r\n\r\n", $deadline);
            [$status, $headers] = self::headers($socket, $deadline);
            $this->ensureRoot();
            $path = rtrim($this->temporaryRoot, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'external-' . bin2hex(random_bytes(16));
            $body = @fopen($path, 'xb');
            if (!is_resource($body)) {
                throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
            }
            @chmod($path, 0600);
            try {
                $bytes = self::body($socket, $body, $headers, $maximumBytes, $deadline);
            } catch (\Throwable $failure) {
                fclose($body);
                @unlink($path);
                throw $failure;
            }
            fclose($body);

            return new StudioPinnedHttpResponse($status, $headers, $path, $bytes);
        } finally {
            fclose($socket);
        }
    }

    /**
     * Build an origin-form request target with no fragment.
     *
     * @param   array<string, int|string>  $parts  Parsed accepted URL.
     *
     * @return  string  HTTP origin-form target.
     *
     * @since   2.0.0
     */
    private static function target(array $parts): string
    {
        $target = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
        if (isset($parts['query']) && is_string($parts['query'])) {
            $target .= '?' . $parts['query'];
        }

        return $target;
    }

    /**
     * Write a complete small HTTP request, handling short socket writes.
     *
     * @param   resource  $socket    Connected TLS stream.
     * @param   string    $request   Complete request headers.
     * @param   float     $deadline  Absolute whole-hop deadline.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function send($socket, string $request, float $deadline): void
    {
        $offset = 0;
        while ($offset < strlen($request)) {
            self::before($deadline);
            $written = @fwrite($socket, substr($request, $offset));
            self::before($deadline);
            if (!is_int($written) || $written < 1) {
                throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
            }
            $offset += $written;
        }
    }

    /**
     * Parse one bounded HTTP/1 response header block, rejecting ambiguity and obsolete folding.
     *
     * @param   resource  $socket    Connected TLS stream.
     * @param   float     $deadline  Absolute monotonic-style wall-clock deadline.
     *
     * @return  array{int, array<string, string>}  Status and lowercase single-value headers.
     *
     * @since   2.0.0
     */
    private static function headers($socket, float $deadline): array
    {
        $statusLine = self::line($socket, 8192, $deadline);
        if (preg_match('/^HTTP\/1\.[01] ([1-5][0-9]{2})(?: |$)/D', rtrim($statusLine, "\r\n"), $match) !== 1) {
            throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
        }
        $headers = [];
        $total = strlen($statusLine);
        while (true) {
            $line = self::line($socket, 8192, $deadline);
            $total += strlen($line);
            if ($total > 32_768) {
                throw new StudioMediaPortRejected('limit-exceeded', 'studio.media/external-header-limit');
            }
            if ($line === "\r\n") {
                break;
            }
            if ($line === "\n" || preg_match('/^[ \t]/', $line) === 1) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }
            $separator = strpos($line, ':');
            if ($separator === false) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }
            $name = strtolower(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            if (
                preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }
            if (isset($headers[$name]) && in_array($name, ['content-length', 'transfer-encoding', 'location'], true)) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }

        return [(int) $match[1], $headers];
    }

    /**
     * Stream a content-length, chunked or EOF-delimited response through the decoded quota.
     *
     * @param   resource               $socket        Connected TLS stream.
     * @param   resource               $target        Private body file.
     * @param   array<string, string>  $headers       Parsed response headers.
     * @param   int                    $maximumBytes  Inclusive decoded quota.
     * @param   float                  $deadline      Absolute whole-hop deadline.
     *
     * @return  int  Exact decoded body bytes.
     *
     * @since   2.0.0
     */
    private static function body($socket, $target, array $headers, int $maximumBytes, float $deadline): int
    {
        $encoding = strtolower($headers['transfer-encoding'] ?? '');
        if ($encoding !== '' && $encoding !== 'chunked') {
            throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
        }
        if ($encoding === 'chunked') {
            if (isset($headers['content-length'])) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }

            return self::chunked($socket, $target, $maximumBytes, $deadline);
        }
        $length = $headers['content-length'] ?? null;
        if (
            $length !== null
            && (preg_match('/^(?:0|[1-9][0-9]*)$/D', $length) !== 1 || (int) $length > $maximumBytes)
        ) {
            throw new StudioMediaPortRejected('limit-exceeded', 'studio.media/external-size-limit');
        }
        $remaining = $length === null ? null : (int) $length;
        $bytes = 0;
        while ($remaining === null || $remaining > 0) {
            self::before($deadline);
            $amount = min(8192, ($remaining ?? $maximumBytes - $bytes + 1));
            $chunk = @fread($socket, max(1, $amount));
            self::before($deadline);
            if (!is_string($chunk) || $chunk === '') {
                if (feof($socket)) {
                    break;
                }
                throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
            }
            self::append($target, $chunk);
            $bytes += strlen($chunk);
            if ($bytes > $maximumBytes) {
                throw new StudioMediaPortRejected('limit-exceeded', 'studio.media/external-size-limit');
            }
            if ($remaining !== null) {
                $remaining -= strlen($chunk);
            }
        }
        if ($remaining !== null && $remaining !== 0) {
            throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
        }

        return $bytes;
    }

    /**
     * Decode an RFC 9112 chunked body without accepting extensions or unbounded trailers.
     *
     * @param   resource  $socket        Connected TLS stream.
     * @param   resource  $target        Private body file.
     * @param   int       $maximumBytes  Inclusive decoded quota.
     * @param   float     $deadline      Absolute whole-hop deadline.
     *
     * @return  int  Exact decoded bytes.
     *
     * @since   2.0.0
     */
    private static function chunked($socket, $target, int $maximumBytes, float $deadline): int
    {
        $bytes = 0;
        while (true) {
            $line = rtrim(self::line($socket, 128, $deadline), "\r\n");
            if (preg_match('/^[0-9A-Fa-f]+$/D', $line) !== 1 || strlen($line) > 16) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }
            $size = hexdec($line);
            if (!is_int($size) || $size < 0 || $size > $maximumBytes - $bytes) {
                throw new StudioMediaPortRejected('limit-exceeded', 'studio.media/external-size-limit');
            }
            if ($size === 0) {
                if (self::line($socket, 8192, $deadline) !== "\r\n") {
                    throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
                }

                return $bytes;
            }
            $remaining = $size;
            while ($remaining > 0) {
                self::before($deadline);
                $chunk = @fread($socket, min(8192, $remaining));
                self::before($deadline);
                if (!is_string($chunk) || $chunk === '') {
                    throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
                }
                self::append($target, $chunk);
                $remaining -= strlen($chunk);
                $bytes += strlen($chunk);
            }
            if (self::line($socket, 2, $deadline) !== "\r\n") {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }
        }
    }

    /**
     * Read one CRLF-terminated bounded line and convert socket timeouts to a safe failure.
     *
     * @param   resource  $socket    Connected TLS stream.
     * @param   int       $limit     Maximum line bytes including delimiter.
     * @param   float     $deadline  Absolute whole-hop deadline.
     *
     * @return  string  Complete line.
     *
     * @since   2.0.0
     */
    private static function line($socket, int $limit, float $deadline): string
    {
        if ($limit < 1 || $limit > 32_768) {
            throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
        }
        self::before($deadline);
        $line = @fgets($socket, $limit + 1);
        self::before($deadline);
        if (!is_string($line) || strlen($line) > $limit || !str_ends_with($line, "\n")) {
            throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
        }

        return $line;
    }

    /**
     * Enforce the whole-hop wall-clock deadline before and after blocking reads.
     *
     * @param   float  $deadline  Absolute wall-clock deadline.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function before(float $deadline): void
    {
        if (microtime(true) > $deadline) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/external-timeout');
        }
    }

    /**
     * Require a complete private-file write.
     *
     * @param   resource  $target  Private body file.
     * @param   string    $chunk   Decoded bytes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function append($target, string $chunk): void
    {
        if (fwrite($target, $chunk) !== strlen($chunk)) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
        }
    }

    /**
     * Create and validate the private download root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function ensureRoot(): void
    {
        if (
            !is_dir($this->temporaryRoot)
            && !@mkdir($this->temporaryRoot, 0700, true)
            && !is_dir($this->temporaryRoot)
        ) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
        }
        if (is_link($this->temporaryRoot) || !is_writable($this->temporaryRoot)) {
            throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
        }
        @chmod($this->temporaryRoot, 0700);
    }
}
