<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Media;

/**
 * Independent PHP implementation of Studio's canonical lexical external-URL policy.
 *
 * This class deliberately performs no DNS lookup. It normalizes the parsed authority before range
 * classification, including WHATWG-style decimal, octal, hexadecimal and dotted-partial IPv4 forms.
 * DNS rebinding, redirect and response checks belong to the hardened application fetcher.
 *
 * @since  2.0.0
 */
final readonly class StudioExternalUrlPolicy
{
    /**
     * Studio's default maximum raw URL length in UTF-16 code units.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_CODE_UNITS = 2048;

    /**
     * Apply Studio's exact default policy before any network resolution occurs.
     *
     * @param   string  $candidate  Author-supplied URL candidate.
     *
     * @return  StudioExternalUrlVerdict  Normalized HTTPS URL or stable lexical refusal.
     *
     * @since   2.0.0
     */
    public function validate(string $candidate): StudioExternalUrlVerdict
    {
        $codeUnits = self::utf16CodeUnits($candidate);
        if ($codeUnits === null) {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::Malformed);
        }
        if ($codeUnits > self::MAXIMUM_CODE_UNITS) {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::UrlTooLong);
        }
        if ($candidate === '' || preg_match('/[\x00-\x20\x7F]/', $candidate) === 1) {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::Malformed);
        }
        $parts = parse_url($candidate);
        if (!is_array($parts) || !is_string($parts['scheme'] ?? null)) {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::Malformed);
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::SchemeNotAllowed);
        }
        if (($parts['user'] ?? '') !== '' || ($parts['pass'] ?? '') !== '') {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::CredentialsInUrl);
        }
        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::HostNotAllowed);
        }
        $normalizedHost = self::normalizeHost($host);
        if ($normalizedHost === null || !self::permittedLexicalHost($normalizedHost)) {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::HostNotAllowed);
        }
        $port = $parts['port'] ?? null;
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::Malformed);
        }

        return StudioExternalUrlVerdict::accepted(self::normalizedUrl($parts, $normalizedHost));
    }

    /**
     * Decide whether one DNS answer is globally routable enough for a privileged host fetch.
     *
     * The runtime guard is intentionally stricter than the lexical policy: PHP's reserved-range flag
     * also rejects documentation, multicast and other non-global ranges that a public DNS answer must
     * never direct the fetcher toward.
     *
     * @param   string  $address  Textual IPv4 or IPv6 answer.
     *
     * @return  bool  True only for a syntactically valid globally routable address.
     *
     * @since   2.0.0
     */
    public function permitsResolvedAddress(string $address): bool
    {
        if (
            filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false
        ) {
            return false;
        }
        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            return false;
        }
        if (strlen($packed) === 4) {
            $unpacked = unpack('Naddress', $packed);
            $numeric = is_array($unpacked) ? ($unpacked['address'] ?? null) : null;

            return is_int($numeric) && !self::disallowedIpv4($numeric);
        }

        return strlen($packed) === 16 && !self::disallowedIpv6($packed);
    }

    /**
     * Resolve an HTTP redirect reference against an already accepted HTTPS URL, then re-validate it.
     *
     * @param   string  $base      Previously accepted absolute URL.
     * @param   string  $location  Absolute or origin-relative Location value.
     *
     * @return  StudioExternalUrlVerdict  Revalidated target or a stable refusal.
     *
     * @since   2.0.0
     */
    public function redirect(string $base, string $location): StudioExternalUrlVerdict
    {
        if ($location === '' || preg_match('/[\x00-\x20\x7F]/', $location) === 1) {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::Malformed);
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $location) === 1) {
            return $this->validate($location);
        }
        $baseParts = parse_url($base);
        if (!is_array($baseParts) || !is_string($baseParts['host'] ?? null)) {
            return StudioExternalUrlVerdict::rejected(StudioExternalUrlRejection::Malformed);
        }
        $authority = 'https://' . $baseParts['host'];
        if (isset($baseParts['port']) && $baseParts['port'] !== 443) {
            $authority .= ':' . $baseParts['port'];
        }
        if (str_starts_with($location, '//')) {
            return $this->validate('https:' . $location);
        }
        if (str_starts_with($location, '/')) {
            return $this->validate($authority . self::removeDotSegments($location));
        }
        $basePath = is_string($baseParts['path'] ?? null) ? $baseParts['path'] : '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        return $this->validate($authority . self::removeDotSegments($directory . $location));
    }

    /**
     * Count JavaScript-compatible UTF-16 code units without accepting malformed UTF-8.
     *
     * @param   string  $value  Candidate UTF-8 text.
     *
     * @return  int|null  Code-unit count, or null for malformed input.
     *
     * @since   2.0.0
     */
    private static function utf16CodeUnits(string $value): ?int
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        return intdiv(strlen(mb_convert_encoding($value, 'UTF-16BE', 'UTF-8')), 2);
    }

    /**
     * Normalize a parsed host the same security decisions Studio's WHATWG implementation observes.
     *
     * @param   string  $host  Host returned by `parse_url()`.
     *
     * @return  string|null  Lowercase ASCII name, canonical IP literal, or null when malformed.
     *
     * @since   2.0.0
     */
    private static function normalizeHost(string $host): ?string
    {
        $bracketed = str_starts_with($host, '[') && str_ends_with($host, ']');
        $literal = $bracketed ? substr($host, 1, -1) : $host;
        if ($literal === '') {
            return null;
        }
        if ($bracketed || str_contains($literal, ':')) {
            $packed = @inet_pton($literal);
            if (!is_string($packed) || strlen($packed) !== 16) {
                return null;
            }
            $canonical = @inet_ntop($packed);

            return is_string($canonical) ? '[' . strtolower($canonical) . ']' : null;
        }
        $numericCandidate = str_ends_with($literal, '.') ? substr($literal, 0, -1) : $literal;
        $numericGrammar = preg_match('/^[0-9A-Fa-fXx.]+$/D', $numericCandidate) === 1;
        $ipv4 = self::parseIpv4Host($numericCandidate);
        if ($ipv4 !== null) {
            return implode('.', [
                (string) (($ipv4 >> 24) & 0xff),
                (string) (($ipv4 >> 16) & 0xff),
                (string) (($ipv4 >> 8) & 0xff),
                (string) ($ipv4 & 0xff),
            ]);
        }
        if ($numericGrammar) {
            return null;
        }
        $lastLabel = substr($numericCandidate, (int) strrpos('.' . $numericCandidate, '.'));
        if (self::parseIpv4Number($lastLabel) !== null || preg_match('/^[0-9]+$/D', $lastLabel) === 1) {
            return null;
        }
        $trailingDot = str_ends_with($literal, '.');
        $name = $trailingDot ? substr($literal, 0, -1) : $literal;
        $info = [];
        $ascii = idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46, $info);
        $errors = is_array($info) ? ($info['errors'] ?? null) : null;
        if (!is_string($ascii) || $ascii === '' || !is_int($errors) || $errors !== 0) {
            return null;
        }

        return strtolower($ascii) . ($trailingDot ? '.' : '');
    }

    /**
     * Apply the canonical special-name and numeric-address exclusions to one normalized host.
     *
     * @param   string  $host  Normalized ASCII host or bracketed IPv6 literal.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    private static function permittedLexicalHost(string $host): bool
    {
        $trimmed = str_ends_with($host, '.') ? substr($host, 0, -1) : $host;
        if ($trimmed === '') {
            return false;
        }
        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $packed = @inet_pton(substr($trimmed, 1, -1));

            return is_string($packed) && !self::disallowedIpv6($packed);
        }
        $lower = strtolower($trimmed);
        if (
            $lower === 'localhost'
            || str_ends_with($lower, '.localhost')
            || str_ends_with($lower, '.local')
            || str_ends_with($lower, '.internal')
            || str_ends_with($lower, '.home.arpa')
        ) {
            return false;
        }
        $address = ip2long($lower);
        if ($address === false) {
            return true;
        }

        return !self::disallowedIpv4((int) sprintf('%u', $address));
    }

    /**
     * Classify the exact IPv4 ranges excluded by Studio's lexical default.
     *
     * @param   int  $address  Unsigned 32-bit address.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    private static function disallowedIpv4(int $address): bool
    {
        $first = ($address >> 24) & 0xff;
        $second = ($address >> 16) & 0xff;

        return $address === 0
            || $address === 0xffffffff
            || $first === 127
            || $first === 10
            || ($first === 172 && $second >= 16 && $second <= 31)
            || ($first === 192 && $second === 168)
            || ($first === 169 && $second === 254)
            || ($first === 100 && $second >= 64 && $second <= 127);
    }

    /**
     * Classify Studio's excluded IPv6 ranges, including embedded IPv4 forms.
     *
     * @param   string  $packed  Sixteen-byte network-order IPv6 address.
     *
     * @return  bool
     *
     * @since   2.0.0
     */
    private static function disallowedIpv6(string $packed): bool
    {
        if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15) . "\1") {
            return true;
        }
        $first = ord($packed[0]);
        $second = ord($packed[1]);
        if (($first === 0xfe && ($second & 0xc0) === 0x80) || ($first & 0xfe) === 0xfc) {
            return true;
        }
        $prefix = substr($packed, 0, 12);
        if ($prefix === str_repeat("\0", 10) . "\xff\xff" || $prefix === str_repeat("\0", 12)) {
            $embedded = unpack('Naddress', substr($packed, 12));
            $numeric = is_array($embedded) ? ($embedded['address'] ?? null) : null;

            return is_int($numeric) && self::disallowedIpv4($numeric);
        }

        return false;
    }

    /**
     * Parse the WHATWG IPv4 host grammar into one unsigned 32-bit address.
     *
     * @param   string  $host  Candidate numeric host.
     *
     * @return  int|null  Address, or null when the host is a name or malformed numeric form.
     *
     * @since   2.0.0
     */
    private static function parseIpv4Host(string $host): ?int
    {
        if (preg_match('/^[0-9A-Fa-fXx.]+$/D', $host) !== 1) {
            return null;
        }
        $parts = explode('.', $host);
        if (count($parts) > 4) {
            return null;
        }
        $numbers = [];
        foreach ($parts as $part) {
            $number = self::parseIpv4Number($part);
            if ($number === null) {
                return null;
            }
            $numbers[] = $number;
        }
        $last = array_pop($numbers);
        if (!is_int($last) || array_any($numbers, static fn (int $number): bool => $number > 255)) {
            return null;
        }
        $remainingBytes = 4 - count($numbers);
        if ($last > (256 ** $remainingBytes) - 1) {
            return null;
        }
        $address = $last;
        foreach ($numbers as $index => $number) {
            $address += $number * (256 ** (3 - $index));
        }

        return $address;
    }

    /**
     * Parse one decimal, octal or hexadecimal WHATWG IPv4 component.
     *
     * @param   string  $part  Candidate component.
     *
     * @return  int|null  Unsigned value or null when malformed or wider than 32 bits.
     *
     * @since   2.0.0
     */
    private static function parseIpv4Number(string $part): ?int
    {
        if ($part === '') {
            return null;
        }
        $digits = $part;
        $radix = 10;
        if (strlen($digits) >= 2 && in_array(substr($digits, 0, 2), ['0x', '0X'], true)) {
            $digits = substr($digits, 2);
            $radix = 16;
            if ($digits === '') {
                return 0;
            }
        } elseif (strlen($digits) >= 2 && $digits[0] === '0') {
            $digits = substr($digits, 1);
            $radix = 8;
        }
        $pattern = $radix === 16 ? '/^[0-9A-Fa-f]+$/D' : ($radix === 8 ? '/^[0-7]+$/D' : '/^[0-9]+$/D');
        if (preg_match($pattern, $digits) !== 1) {
            return null;
        }
        $value = intval($digits, $radix);

        return $value <= 0xffffffff ? $value : null;
    }

    /**
     * Rebuild a normalized HTTPS URL from already classified components.
     *
     * @param   array<string, int|string>  $parts  Parsed URL components.
     * @param   string                     $host   Normalized host.
     *
     * @return  string  Absolute URL with lowercase authority and an explicit root path.
     *
     * @since   2.0.0
     */
    private static function normalizedUrl(array $parts, string $host): string
    {
        $url = 'https://' . $host;
        if (isset($parts['port']) && $parts['port'] !== 443) {
            $url .= ':' . $parts['port'];
        }
        $path = is_string($parts['path'] ?? null) && $parts['path'] !== '' ? $parts['path'] : '/';
        $url .= $path;
        if (isset($parts['query'])) {
            $url .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }

    /**
     * Collapse dot segments in one origin-relative redirect path.
     *
     * @param   string  $path  Absolute path, possibly carrying a query or fragment.
     *
     * @return  string  Rooted path with dot segments removed.
     *
     * @since   2.0.0
     */
    private static function removeDotSegments(string $path): string
    {
        $suffixOffset = strcspn($path, '?#');
        $pathname = substr($path, 0, $suffixOffset);
        $suffix = substr($path, $suffixOffset);
        $segments = [];
        foreach (explode('/', $pathname) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments) . $suffix;
    }
}
