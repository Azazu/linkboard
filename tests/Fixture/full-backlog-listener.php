<?php

declare(strict_types=1);

// A TCP listener whose accept queue is already full: listen(0) allows one
// completed connection in the queue, this script parks its own connection
// there and never calls accept(). Linux then drops the SYN of every further
// client (tcp_conn_request → sk_acceptq_is_full → drop, with the default
// tcp_abort_on_overflow=0), so a client's connect() stalls until the client's
// own connect timeout — the "connect that never completes" case of the Redis
// memory. A delayed accept() would not do: an established connection waits in
// the queue and only the client's first read would stall. Prints the port on
// the first stdout line; stopped by the test in `finally`.

$context = stream_context_create(['socket' => ['backlog' => 0]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN, $context);
if (false === $server) {
    fwrite(\STDERR, "bind failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
$port = substr((string) strrchr((string) $name, ':'), 1);

$parked = stream_socket_client("tcp://127.0.0.1:$port", $errno, $errstr, 2);
if (false === $parked) {
    fwrite(\STDERR, "parking connection failed: $errstr\n");
    exit(1);
}

fwrite(\STDOUT, $port."\n");
fflush(\STDOUT);
while (true) {
    sleep(60);
}
