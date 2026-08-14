<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Infrastructure\Trust;

use Kumwe\CMS\Extension\Application\Trust\RevocationFeedSource;
use Kumwe\CMS\Extension\Application\Trust\RevocationList;
use RuntimeException;

/**
 * Reads a revocation list from an HTTPS origin or from a local mirror, whichever the operator pinned.
 *
 * Two origins are supported and the scheme decides which. An absolute path reads a file, which is the
 * mode for an installation with no egress: whatever the operator already uses to move bytes onto the
 * host — configuration management, a cron that curls, a mounted volume — becomes the transport, and
 * PHP makes no outbound connection at all. An `https://` URL fetches directly, with peer and hostname
 * verification on, redirects refused, a short timeout, and a hard read ceiling. Plain `http://` is
 * refused by configuration before it ever reaches here.
 *
 * TLS is a hygiene measure rather than the trust boundary. What makes a fetched list believable is the
 * Ed25519 signature over its statement and the monotonic sequence that stops a rollback; a transport
 * compromise can withhold a list or serve a stale one, which is precisely the failure the synchronizer
 * treats as staleness rather than as a reason to stop serving.
 *
 * @since  2.0.0
 */
final readonly class StreamRevocationFeedSource implements RevocationFeedSource
{
    /**
     * Seconds allowed for connection and transfer before a fetch is abandoned.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int TIMEOUT_SECONDS = 10;

    /**
     * Fetch the envelope bytes from the configured origin.
     *
     * @param   string  $origin  Absolute `https://` URL or absolute local path.
     *
     * @return  string  Raw envelope bytes exactly as served.
     *
     * @throws  RuntimeException  When the origin is neither supported form, the local file is missing or
     *          unsafe, the request fails, or the response exceeds the accepted maximum.
     *
     * @since   2.0.0
     */
    public function fetch(string $origin): string
    {
        if (str_starts_with($origin, '/')) {
            return $this->readFile($origin);
        }
        if (!str_starts_with($origin, 'https://')) {
            throw new RuntimeException('A revocation feed origin must be an https:// URL or an absolute path.');
        }

        return $this->readUrl($origin);
    }

    /**
     * Read a mirrored list from the local filesystem.
     *
     * @param   string  $path  Absolute path to the mirrored envelope.
     *
     * @return  string  Raw envelope bytes.
     *
     * @throws  RuntimeException  When the path is not a readable regular file, is a symbolic link, is
     *          empty, or exceeds the accepted maximum.
     *
     * @since   2.0.0
     */
    private function readFile(string $path): string
    {
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new RuntimeException('The revocation feed mirror must be a readable regular file.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > RevocationList::MAXIMUM_BYTES) {
            throw new RuntimeException('The revocation feed mirror is empty or exceeds 1 MiB.');
        }
        $payload = file_get_contents($path, false, null, 0, RevocationList::MAXIMUM_BYTES + 1);
        if (!is_string($payload) || $payload === '') {
            throw new RuntimeException('The revocation feed mirror could not be read.');
        }

        return $payload;
    }

    /**
     * Fetch a list over HTTPS with verification on and redirects refused.
     *
     * @param   string  $url  Absolute `https://` URL.
     *
     * @return  string  Raw envelope bytes.
     *
     * @throws  RuntimeException  When the request fails, the origin answers a non-success status, or the
     *          response exceeds the accepted maximum.
     *
     * @since   2.0.0
     */
    private function readUrl(string $url): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'follow_location' => 0,
                'max_redirects' => 0,
                'ignore_errors' => true,
                'timeout' => self::TIMEOUT_SECONDS,
                'header' => "Accept: application/json\r\nUser-Agent: kumwe-revocation-feed/1\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        http_clear_last_response_headers();
        $payload = @file_get_contents($url, false, $context, 0, RevocationList::MAXIMUM_BYTES + 1);
        if (!is_string($payload)) {
            throw new RuntimeException('The revocation feed origin could not be reached.');
        }
        $this->assertSuccessful(http_get_last_response_headers() ?? []);
        if ($payload === '' || strlen($payload) > RevocationList::MAXIMUM_BYTES) {
            throw new RuntimeException('The revocation feed response is empty or exceeds 1 MiB.');
        }

        return $payload;
    }

    /**
     * Require a 2xx status on the response the stream wrapper recorded.
     *
     * @param   list<string>  $headers  Raw response headers as the wrapper recorded them.
     *
     * @return  void
     *
     * @throws  RuntimeException  When no status line is present or the status is not a success.
     *
     * @since   2.0.0
     */
    private function assertSuccessful(array $headers): void
    {
        $status = $headers[0] ?? null;
        if (!is_string($status) || preg_match('#^HTTP/[0-9.]+ (\d{3})#', $status, $matches) !== 1) {
            throw new RuntimeException('The revocation feed origin returned no readable status line.');
        }
        $code = (int) $matches[1];
        if ($code < 200 || $code > 299) {
            throw new RuntimeException(sprintf('The revocation feed origin answered HTTP %d.', $code));
        }
    }
}
