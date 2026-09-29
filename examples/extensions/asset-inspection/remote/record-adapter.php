<?php

/**
 * Read or conditionally update one business record from a separately deployed PHP process.
 *
 * Usage: php record-adapter.php https://kumwe.example < protected-request.json
 * A test-only --allow-loopback-http argument permits a literal 127.0.0.1 HTTP origin.
 * The bounded stdin document carries the scoped bearer credential; no host autoloader is loaded.
 * This is an authenticated REST client, never a sandbox for installed extension PHP.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

try {
    $origin = $argv[1] ?? '';
    $loopback = ($argv[2] ?? '') === '--allow-loopback-http';
    if ($argc !== ($loopback ? 3 : 2)) {
        throw new InvalidArgumentException('Invalid invocation.');
    }
    $https = preg_match('#^https://[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?(?::[0-9]{1,5})?$#D', $origin) === 1;
    $local = $loopback && preg_match('#^http://127\.0\.0\.1:[0-9]{1,5}$#D', $origin) === 1;
    if (!$https && !$local) {
        throw new InvalidArgumentException('An explicit secure origin is required.');
    }
    stream_set_blocking(STDIN, false);
    $input = '';
    $deadline = hrtime(true) + 5_000_000_000;
    while (!feof(STDIN)) {
        if (hrtime(true) >= $deadline) {
            throw new InvalidArgumentException('The request exceeded its time budget.');
        }
        $read = [STDIN];
        $write = null;
        $except = null;
        if (stream_select($read, $write, $except, 0, 100000) === false) {
            throw new RuntimeException('The request could not be read.');
        }
        if ($read === []) {
            continue;
        }
        $bytes = fread(STDIN, 32769 - strlen($input));
        if ($bytes === false || strlen($input) + strlen($bytes) > 32768) {
            throw new InvalidArgumentException('The request exceeds its byte budget.');
        }
        $input .= $bytes;
    }
    $request = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_is_list($request)) {
        throw new InvalidArgumentException('The request must be an object.');
    }
    $update = ($request['operation'] ?? null) === 'update';
    $keys = ['token', 'site', 'definition', 'record', 'operation'];
    if ($update) {
        $keys = [...$keys, 'etag', 'idempotency_key', 'values'];
    }
    $actualKeys = array_keys($request);
    sort($keys);
    sort($actualKeys);
    if ($actualKeys !== $keys || !in_array($request['operation'], ['read', 'update'], true)) {
        throw new InvalidArgumentException('Unknown operation or request members.');
    }
    foreach (['token' => 4096, 'site' => 100, 'definition' => 200, 'record' => 36] as $field => $maximum) {
        $value = $request[$field];
        if (
            !is_string($value) || $value === '' || strlen($value) > $maximum
            || preg_match('/[\x00-\x20\x7f]/', $value) !== 0
        ) {
            throw new InvalidArgumentException('Invalid request identity.');
        }
    }
    if (
        preg_match('/^[a-z][a-z0-9_.-]*$/D', $request['definition']) !== 1
        || preg_match('/^[a-z][a-z0-9_-]*$/D', $request['site']) !== 1
        || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $request['record']) !== 1
    ) {
        throw new InvalidArgumentException('Invalid resource identity.');
    }
    $headers = [
        'Authorization: Bearer ' . $request['token'],
        'Kumwe-Site: ' . $request['site'],
        'Accept: application/json',
    ];
    $payload = null;
    if ($update) {
        if (
            !is_string($request['etag'])
            || preg_match('/^"[\x21\x23-\x7e]{1,200}"$/D', $request['etag']) !== 1
            || !is_string($request['idempotency_key'])
            || preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $request['idempotency_key']) !== 1
            || !is_array($request['values']) || $request['values'] === [] || array_is_list($request['values'])
            || count($request['values']) > 64
        ) {
            throw new InvalidArgumentException('A bounded update requires its original strong ETag and operation key.');
        }
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'If-Match: ' . $request['etag'];
        $headers[] = 'Idempotency-Key: ' . $request['idempotency_key'];
        $payload = json_encode(['values' => $request['values']], JSON_THROW_ON_ERROR);
    }
    $url = $origin . '/api/v1/business/records/' . $request['definition'] . '/' . $request['record'];
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('The transport could not be initialized.');
    }
    $body = '';
    $etag = '';
    $headerBytes = 0;
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $update ? 'PATCH' : 'GET',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => $local ? CURLPROTO_HTTP : CURLPROTO_HTTPS,
        CURLOPT_PROXY => '',
        CURLOPT_CONNECTTIMEOUT_MS => 2000,
        CURLOPT_TIMEOUT_MS => 5000,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function (CurlHandle $curl, string $bytes) use (&$body): int {
            if (strlen($body) + strlen($bytes) > 65536) {
                return 0;
            }
            $body .= $bytes;
            return strlen($bytes);
        },
        CURLOPT_HEADERFUNCTION => static function (CurlHandle $curl, string $line) use (&$etag, &$headerBytes): int {
            $headerBytes += strlen($line);
            if ($headerBytes > 16384) {
                return 0;
            }
            if (str_starts_with(strtolower($line), 'etag:')) {
                $etag = trim(substr($line, 5));
            }
            return strlen($line);
        },
    ]);
    if ($payload !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
    }
    if (curl_exec($handle) === false) {
        throw new RuntimeException('The bounded transport failed; preserve the original operation for reconciliation.');
    }
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    if ($status < 200 || $status >= 300) {
        // A refusal never forwards a host error body or a redirect location to an adapter log.
        fwrite(STDOUT, json_encode(['status' => $status], JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
    $document = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    fwrite(STDOUT, json_encode([
        'status' => $status,
        'etag' => $etag,
        'document' => $document,
    ], JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable) {
    // Process boundary: never disclose request values, credentials, destinations or transport diagnostics.
    fwrite(STDERR, "The adapter request failed validation or its bounded transport.\n");
    exit(2);
}
