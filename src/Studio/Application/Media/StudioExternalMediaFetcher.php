<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Media;

use Kumwe\App\Studio\Domain\Media\StudioExternalUrlPolicy;

/**
 * Hardened external-media importer with lexical-first SSRF policy and pinned one-hop transport.
 *
 * Every redirect starts the lexical/DNS/pinning sequence again. All DNS answers must be globally
 * routable, preventing an attacker from mixing an acceptable answer with an internal rebinding target.
 * Response bodies are bounded while streaming, content encodings are refused, and the declared media
 * type must agree with both byte detection and a supported signature. Failures retain no URL or address.
 *
 * @since  2.0.0
 */
final readonly class StudioExternalMediaFetcher
{
    /**
     * Compose the complete external-fetch security boundary.
     *
     * @param  StudioExternalUrlPolicy        $urls              Canonical lexical and address classifier.
     * @param  StudioExternalAddressResolver  $resolver          DNS answer source.
     * @param  StudioPinnedHttpTransport      $transport         TLS-verified pinned one-hop transport.
     * @param  StudioMediaSignatureVerifier   $signatures        Byte signature verifier.
     * @param  list<string>                   $mediaTypes        Closed accepted response types.
     * @param  int                            $maximumBytes      Inclusive decoded body quota.
     * @param  int                            $maximumRedirects  Redirect-hop quota.
     * @param  int                            $timeoutSeconds    Whole import time quota.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioExternalUrlPolicy $urls,
        private StudioExternalAddressResolver $resolver,
        private StudioPinnedHttpTransport $transport,
        private StudioMediaSignatureVerifier $signatures,
        private array $mediaTypes,
        private int $maximumBytes,
        private int $maximumRedirects = 3,
        private int $timeoutSeconds = 10,
    ) {
    }

    /**
     * Fetch and verify one author-supplied candidate without ever surfacing its value in an error.
     *
     * @param   string  $candidate  Untrusted external URL.
     *
     * @return  StudioFetchedMedia  Verified private payload.
     *
     * @throws  StudioMediaPortRejected  For every policy, network, quota or verification failure.
     *
     * @since   2.0.0
     */
    public function fetch(string $candidate): StudioFetchedMedia
    {
        $verdict = $this->urls->validate($candidate);
        if (!$verdict->acceptedUrl() || $verdict->url === null) {
            throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-url-refused');
        }
        $url = $verdict->url;
        $started = microtime(true);

        for ($hop = 0; $hop <= $this->maximumRedirects; $hop++) {
            $remaining = $this->timeoutSeconds - (int) floor(microtime(true) - $started);
            if ($remaining < 1) {
                throw new StudioMediaPortRejected('unavailable', 'studio.media/external-timeout');
            }
            $host = self::host($url);
            $addresses = $this->resolver->resolve($host);
            if ($addresses === [] || count($addresses) > 16) {
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-host-refused');
            }
            foreach ($addresses as $address) {
                if (!$this->urls->permitsResolvedAddress($address)) {
                    throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-host-refused');
                }
            }
            try {
                $response = $this->transport->get(
                    $url,
                    $addresses[0],
                    $this->maximumBytes,
                    min($remaining, $this->timeoutSeconds),
                );
            } catch (StudioMediaPortRejected $failure) {
                throw $failure;
            } catch (\Throwable) {
                throw new StudioMediaPortRejected('unavailable', 'studio.media/external-fetch-failed');
            }
            if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                try {
                    self::discard($response->path);
                    if ($hop === $this->maximumRedirects) {
                        throw new StudioMediaPortRejected('limit-exceeded', 'studio.media/external-redirect-limit');
                    }
                    $location = $response->headers['location'] ?? null;
                    if (!is_string($location)) {
                        throw new StudioMediaPortRejected(
                            'validation-failed',
                            'studio.media/external-redirect-refused',
                        );
                    }
                    $redirect = $this->urls->redirect($url, $location);
                    if (!$redirect->acceptedUrl() || $redirect->url === null) {
                        throw new StudioMediaPortRejected(
                            'validation-failed',
                            'studio.media/external-redirect-refused',
                        );
                    }
                    $url = $redirect->url;
                    continue;
                } catch (StudioMediaPortRejected $failure) {
                    throw $failure;
                }
            }
            if ($response->status !== 200 || $response->bytes < 1) {
                self::discard($response->path);
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }
            if (($response->headers['content-encoding'] ?? 'identity') !== 'identity') {
                self::discard($response->path);
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-response-refused');
            }
            $declared = strtolower(trim(explode(';', $response->headers['content-type'] ?? '', 2)[0]));
            $detected = $this->signatures->verify($response->path);
            if (
                $detected === null
                || !in_array($detected, $this->mediaTypes, true)
                || !hash_equals($detected, $declared)
            ) {
                self::discard($response->path);
                throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-type-refused');
            }

            return new StudioFetchedMedia(
                $response->path,
                self::filename($url, $detected),
                $detected,
                $response->bytes,
            );
        }

        throw new StudioMediaPortRejected('limit-exceeded', 'studio.media/external-redirect-limit');
    }

    /**
     * Extract the normalized hostname without IPv6 brackets.
     *
     * @param   string  $url  Accepted normalized URL.
     *
     * @return  string  Hostname or textual address.
     *
     * @since   2.0.0
     */
    private static function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new StudioMediaPortRejected('validation-failed', 'studio.media/external-url-refused');
        }

        return trim($host, '[]');
    }

    /**
     * Derive a safe display name without retaining authority or query data.
     *
     * @param   string  $url        Accepted normalized URL.
     * @param   string  $mediaType  Verified response media type.
     *
     * @return  string  Safe bounded display name.
     *
     * @since   2.0.0
     */
    private static function filename(string $url, string $mediaType): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $name = is_string($path) ? rawurldecode(basename($path)) : '';
        $name = preg_replace('/[^\pL\pN._ -]+/u', '-', $name) ?? '';
        $name = trim(substr($name, 0, 255), '. -');

        return $name !== '' ? $name : 'external.' . match ($mediaType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => 'pdf',
        };
    }

    /**
     * Best-effort discard one private temporary body.
     *
     * @param   string  $path  Private body path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function discard(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
