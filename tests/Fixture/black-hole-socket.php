<?php

declare(strict_types=1);

// A TCP endpoint that accepts every connection and never sends a byte: the
// "connection accepted, handshake never completes" dependency of the
// health-check spec. Binds 127.0.0.1:0 and prints the chosen port on the
// first stdout line. Client bytes are read and discarded so the client keeps
// waiting for an answer that never comes. Started with Symfony Process and
// stopped (SIGTERM) by the test in `finally`.

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    fwrite(\STDERR, "bind failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(\STDOUT, substr((string) strrchr((string) $name, ':'), 1)."\n");
fflush(\STDOUT);

/** @var array<int, resource> $clients */
$clients = [];
while (true) {
    $read = [$server, ...array_values($clients)];
    $write = null;
    $except = null;
    if (false === @stream_select($read, $write, $except, 5)) {
        continue;
    }
    foreach ($read as $stream) {
        if ($stream === $server) {
            $client = @stream_socket_accept($server, 0);
            if (false !== $client) {
                $clients[(int) $client] = $client;
            }
            continue;
        }
        $data = fread($stream, 8192);
        if ((false === $data || '' === $data) && feof($stream)) {
            fclose($stream);
            unset($clients[(int) $stream]);
        }
    }
}
