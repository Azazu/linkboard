<?php

declare(strict_types=1);

// A TCP proxy in front of PostgreSQL that misbehaves on purpose (design
// decision 7 of authorize-deep-probe-by-api-key):
//   --upstream=host:port         where the real server is (required)
//   --delay=<seconds>            wait before connecting upstream: the client's
//                                TCP connect succeeds at once, the handshake
//                                starts only after the delay
//   --stall-after-responses=<n>  forward server→client until n server responses
//                                have passed (a response ends with the
//                                ReadyForQuery message 'Z'; the startup
//                                handshake is response 1), then swallow every
//                                further server byte while still forwarding
//                                client→server: a completed handshake followed
//                                by a lost query response
// Binds 127.0.0.1:0 and prints the port on the first stdout line. Frames the
// server stream by the PostgreSQL message header (type byte + int32 length);
// the single-byte answer to an SSLRequest/GSSENCRequest is passed through
// before framing starts. One connection at a time; stopped by the test.

$upstream = null;
$delay = 0.0;
$stallAfter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (1 === preg_match('/^--upstream=(.+):(\d+)$/', $arg, $m)) {
        $upstream = [$m[1], (int) $m[2]];
    } elseif (1 === preg_match('/^--delay=([\d.]+)$/', $arg, $m)) {
        $delay = (float) $m[1];
    } elseif (1 === preg_match('/^--stall-after-responses=(\d+)$/', $arg, $m)) {
        $stallAfter = (int) $m[1];
    } else {
        fwrite(\STDERR, "unknown argument $arg\n");
        exit(64);
    }
}
if (null === $upstream) {
    fwrite(\STDERR, "--upstream=host:port is required\n");
    exit(64);
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    fwrite(\STDERR, "bind failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(\STDOUT, substr((string) strrchr((string) $name, ':'), 1)."\n");
fflush(\STDOUT);

/** @param resource $stream */
function writeAll($stream, string $data): void
{
    while ('' !== $data) {
        $read = null;
        $write = [$stream];
        $except = null;
        if (false === @stream_select($read, $write, $except, 5)) {
            return;
        }
        $written = @fwrite($stream, $data);
        if (false === $written || 0 === $written) {
            return;
        }
        $data = substr($data, $written);
    }
}

while (true) {
    $client = @stream_socket_accept($server, -1);
    if (false === $client) {
        continue;
    }
    if ($delay > 0) {
        usleep((int) ($delay * 1_000_000));
    }
    $up = @stream_socket_client(sprintf('tcp://%s:%d', $upstream[0], $upstream[1]), $errno, $errstr, 5);
    if (false === $up) {
        fclose($client);
        continue;
    }
    stream_set_blocking($client, false);
    stream_set_blocking($up, false);

    $responses = 0;
    $stalled = false;
    $buffer = '';
    $firstClientMessage = true;
    $expectSingleByte = false;
    $passthrough = false;

    while (true) {
        $read = [$client, $up];
        $write = null;
        $except = null;
        if (false === @stream_select($read, $write, $except, 5)) {
            break;
        }
        $closed = false;
        foreach ($read as $stream) {
            $data = fread($stream, 65536);
            if (false === $data || ('' === $data && feof($stream))) {
                $closed = true;
                break;
            }
            if ('' === $data) {
                continue;
            }
            if ($stream === $client) {
                if ($firstClientMessage) {
                    $firstClientMessage = false;
                    if (8 === strlen($data)) {
                        $code = unpack('N', substr($data, 4, 4));
                        $expectSingleByte = false !== $code && in_array($code[1], [80877103, 80877104], true);
                    }
                }
                writeAll($up, $data);
                continue;
            }
            if ($stalled) {
                continue; // the lost response: read and drop
            }
            if ($passthrough) {
                writeAll($client, $data);
                continue;
            }
            $buffer .= $data;
            if ($expectSingleByte) {
                $answer = $buffer[0];
                $buffer = substr($buffer, 1);
                $expectSingleByte = false;
                writeAll($client, $answer);
                if ('S' === $answer) {
                    $passthrough = true; // TLS from here on: nothing to frame
                    writeAll($client, $buffer);
                    $buffer = '';
                    continue;
                }
            }
            while (strlen($buffer) >= 5) {
                $length = unpack('N', substr($buffer, 1, 4));
                if (false === $length || strlen($buffer) < 1 + $length[1]) {
                    break;
                }
                $frame = substr($buffer, 0, 1 + $length[1]);
                $buffer = substr($buffer, 1 + $length[1]);
                writeAll($client, $frame);
                if ('Z' === $frame[0] && null !== $stallAfter && ++$responses >= $stallAfter) {
                    $stalled = true;
                    $buffer = '';
                    break;
                }
            }
        }
        if ($closed) {
            break;
        }
    }
    @fclose($client);
    @fclose($up);
}
