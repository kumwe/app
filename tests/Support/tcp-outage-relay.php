<?php

/**
 * The killable process an infrastructure outage needs, run as a separate operating-system relay.
 *
 * A drill that claims to prove outage behaviour cannot fake the outage inside the process under test:
 * a stub that throws proves only that the stub throws, and a client-side `close()` proves only that a
 * client can close a socket. This script puts a real, killable process on the network path between the
 * runtime and the server it depends on — Redis in one drill, the database in another. The drill points
 * the runtime's own connection settings at this relay's port, exercises the real client through it, and
 * then `SIGKILL`s this process. Every established connection is reset and every new connection is
 * refused from that moment, which is exactly the state a stopped or restarting server leaves a client
 * in, including the part that matters most: reconnecting does not work either. Starting the relay again
 * restores the path, so the same drill can go on to prove recovery.
 *
 * Byte forwarding is deliberately dumb: the relay never parses a protocol, so what the runtime talks to
 * is the real server and the only thing under the drill's control is whether the path exists.
 *
 * Usage: php tests/Support/tcp-outage-relay.php <listen-port> <upstream-host> <upstream-port> <ready-file>
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$listenPort = $argv[1] ?? null;
$upstreamHost = $argv[2] ?? null;
$upstreamPort = $argv[3] ?? null;
$readyFile = $argv[4] ?? null;
if (
    !is_string($listenPort) || !is_string($upstreamHost)
    || !is_string($upstreamPort) || !is_string($readyFile)
) {
    fwrite(STDERR, "Usage: tcp-outage-relay.php <listen-port> <upstream-host> <upstream-port> <ready-file>\n");
    exit(2);
}

$listener = @stream_socket_server(
    sprintf('tcp://127.0.0.1:%d', (int) $listenPort),
    $errorCode,
    $errorMessage,
);
if ($listener === false) {
    fwrite(STDERR, sprintf("tcp-outage-relay: cannot listen (%d) %s\n", $errorCode, $errorMessage));
    exit(1);
}
stream_set_blocking($listener, false);
file_put_contents($readyFile, (string) getmypid());

/** @var array<int, array{client: resource, upstream: resource}> $pairs */
$pairs = [];
$nextPair = 0;

while (true) {
    $read = [$listener];
    foreach ($pairs as $pair) {
        $read[] = $pair['client'];
        $read[] = $pair['upstream'];
    }
    $write = null;
    $except = null;
    if (@stream_select($read, $write, $except, 0, 20_000) === false) {
        continue;
    }

    foreach ($read as $stream) {
        if ($stream === $listener) {
            $client = @stream_socket_accept($listener, 0);
            if ($client === false) {
                continue;
            }
            $upstream = @stream_socket_client(
                sprintf('tcp://%s:%d', $upstreamHost, (int) $upstreamPort),
                $errorCode,
                $errorMessage,
                2.0,
            );
            if ($upstream === false) {
                fclose($client);
                continue;
            }
            stream_set_blocking($client, false);
            stream_set_blocking($upstream, false);
            $pairs[$nextPair++] = ['client' => $client, 'upstream' => $upstream];
            continue;
        }

        foreach ($pairs as $index => $pair) {
            $source = null;
            $sink = null;
            if ($stream === $pair['client']) {
                $source = $pair['client'];
                $sink = $pair['upstream'];
            } elseif ($stream === $pair['upstream']) {
                $source = $pair['upstream'];
                $sink = $pair['client'];
            }
            if ($source === null || $sink === null) {
                continue;
            }
            $bytes = @fread($source, 65_536);
            if ($bytes === false || $bytes === '') {
                fclose($pair['client']);
                fclose($pair['upstream']);
                unset($pairs[$index]);
                continue;
            }
            @fwrite($sink, $bytes);
        }
    }
}
