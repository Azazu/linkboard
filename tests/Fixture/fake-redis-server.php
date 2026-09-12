<?php

declare(strict_types=1);

// A minimal RESP server standing in for Redis in the memory's timeout tests
// (design decision 7 of authorize-deep-probe-by-api-key). Answers AUTH with
// +OK, PING with +PONG, GET with a null bulk ($-1), EVAL with :1.
//   --response-delay=<seconds>   sleep before answering
//   --slow-from-command=<n>      apply the delay only from the n-th RESP
//                                command of a connection on (1-based); the
//                                memory sends exactly three per request:
//                                AUTH/PING = 1, the token GET = 2, the
//                                post-lookup command = 3 — the TCP connect
//                                is not a command
//   --command-log=<file>         append "received <CMD> <unix time>" and
//                                "answered <CMD> <unix time>" lines so a test
//                                asserts which commands completed
//   --get-value=<string>         answer GET with this bulk string instead of a
//                                null bulk: a memory that HAS a remembered
//                                verification, served as slowly as the rest
// Binds 127.0.0.1:0, prints the port on the first stdout line, serves one
// connection at a time; stopped by the test in `finally`.

$delay = 0.0;
$slowFrom = 1;
$log = null;
$getValue = null;
foreach (array_slice($argv, 1) as $arg) {
    if (1 === preg_match('/^--response-delay=([\d.]+)$/', $arg, $m)) {
        $delay = (float) $m[1];
    } elseif (1 === preg_match('/^--slow-from-command=(\d+)$/', $arg, $m)) {
        $slowFrom = (int) $m[1];
    } elseif (1 === preg_match('/^--command-log=(.+)$/', $arg, $m)) {
        $log = $m[1];
    } elseif (1 === preg_match('/^--get-value=(.*)$/s', $arg, $m)) {
        $getValue = $m[1];
    } else {
        fwrite(\STDERR, "unknown argument $arg\n");
        exit(64);
    }
}

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    fwrite(\STDERR, "bind failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
fwrite(\STDOUT, substr((string) strrchr((string) $name, ':'), 1)."\n");
fflush(\STDOUT);

/**
 * Parses one RESP array command from the front of $buffer.
 *
 * @return array{0: list<string>, 1: string}|null the command words and the rest, or null when incomplete
 */
function parseCommand(string $buffer): ?array
{
    if ('' === $buffer || '*' !== $buffer[0]) {
        return null;
    }
    $end = strpos($buffer, "\r\n");
    if (false === $end) {
        return null;
    }
    $count = (int) substr($buffer, 1, $end - 1);
    $offset = $end + 2;
    $words = [];
    for ($i = 0; $i < $count; ++$i) {
        if (!isset($buffer[$offset]) || '$' !== $buffer[$offset]) {
            return null;
        }
        $lengthEnd = strpos($buffer, "\r\n", $offset);
        if (false === $lengthEnd) {
            return null;
        }
        $length = (int) substr($buffer, $offset + 1, $lengthEnd - $offset - 1);
        $start = $lengthEnd + 2;
        if (strlen($buffer) < $start + $length + 2) {
            return null;
        }
        $words[] = substr($buffer, $start, $length);
        $offset = $start + $length + 2;
    }

    return [$words, substr($buffer, $offset)];
}

function logLine(?string $file, string $kind, string $command): void
{
    if (null !== $file) {
        file_put_contents($file, sprintf("%s %s %.6f\n", $kind, $command, microtime(true)), \FILE_APPEND | \LOCK_EX);
    }
}

while (true) {
    $client = @stream_socket_accept($server, -1);
    if (false === $client) {
        continue;
    }
    $index = 0;
    $buffer = '';
    while (true) {
        $data = fread($client, 65536);
        if (false === $data || ('' === $data && feof($client))) {
            break;
        }
        $buffer .= $data;
        while (null !== ($parsed = parseCommand($buffer))) {
            [$words, $buffer] = $parsed;
            ++$index;
            $command = strtoupper($words[0] ?? '');
            logLine($log, 'received', $command);
            if ($delay > 0 && $index >= $slowFrom) {
                usleep((int) ($delay * 1_000_000));
            }
            $reply = match ($command) {
                'AUTH', 'SELECT' => "+OK\r\n",
                'PING' => "+PONG\r\n",
                'GET' => null === $getValue ? "$-1\r\n" : sprintf("$%d\r\n%s\r\n", strlen($getValue), $getValue),
                'EVAL' => ":1\r\n",
                default => "-ERR unknown command\r\n",
            };
            @fwrite($client, $reply);
            logLine($log, 'answered', $command);
        }
    }
    @fclose($client);
}
