<?php

declare(strict_types=1);

/**
 * Builds a minimal MaxMind-DB country database for the deployment's
 * country-routing verification (change stretch-public-hosting, Gate 2
 * confirmation 1, finding 2).
 *
 * It is NOT the licensed GeoLite2 dataset: it is a database in MaxMind's own
 * documented binary format, holding one prefix, that the real
 * `MaxMind\Db\Reader` parses. That is enough for what has to be shown — that a
 * provisioned database is mounted and readable inside the php container and
 * that a redirect from an address it knows resolves to that country and
 * matches a country rule. Obtaining the real dataset needs a MaxMind account
 * and a licence key, which is an operator's step and never this repository's.
 *
 *   docker compose exec php php tests/Fixture/build-country-mmdb.php \
 *       [<prefix> <length> <country> <output path>]
 *
 * With no arguments it writes var/Test-Country.mmdb mapping 203.0.113.0/24 to
 * DE — TEST-NET-3, reserved for documentation, so the default can never
 * describe a real address. Nothing binary is committed: the database is built
 * where it is needed.
 */
const RECORD_SIZE = 32;

// Defaults: TEST-NET-3, reserved for documentation, so the committed fixture
// can never describe a real address. The production-stack check overrides them
// with the container network's own range, because the address a container
// really connects from is private — mapping THAT is what makes the check end
// to end rather than a spoofed header.
[$prefix, $prefixLength, $country, $out] = [
    $argv[1] ?? '203.0.113.0',
    (int) ($argv[2] ?? 24),
    $argv[3] ?? 'DE',
    $argv[4] ?? dirname(__DIR__, 2).'/var/Test-Country.mmdb',
];

/** A control byte plus payload, per the MaxMind DB data-format spec. */
function utf8(string $value): string
{
    return control(2, strlen($value)).$value;
}

function uint(int $value, int $type): string
{
    $bytes = '';
    while ($value > 0) {
        $bytes = chr($value & 0xFF).$bytes;
        $value >>= 8;
    }

    return control($type, strlen($bytes)).$bytes;
}

/** @param list<string> $items */
function arr(array $items): string
{
    return control(11, count($items)).implode('', $items);
}

/** @param array<string, string> $pairs already-encoded values, keyed by their plain key */
function map(array $pairs): string
{
    $out = control(7, count($pairs));
    foreach ($pairs as $key => $encoded) {
        $out .= utf8($key).$encoded;
    }

    return $out;
}

function control(int $type, int $size): string
{
    if ($size >= 29) {
        throw new RuntimeException('this builder only writes payloads shorter than 29 bytes');
    }

    // types 8 and above are "extended": the control byte's type bits are 0 and
    // the following byte carries the type minus 7
    return $type < 8
        ? chr(($type << 5) | $size)
        : chr($size).chr($type - 7);
}

// --- the data section ------------------------------------------------------
$data = map(['country' => map(['iso_code' => utf8($country)])]);

// --- the search tree -------------------------------------------------------
// One node per bit of the prefix. At each node the record for the prefix's
// own bit points at the next node, and the other record is "no data".
$nodeCount = $prefixLength;
$noData = $nodeCount;                 // a record equal to the node count means empty
$dataPointer = $nodeCount + 16;       // the data section starts 16 bytes after the tree
$address = ip2long($prefix);
if (false === $address) {
    throw new RuntimeException('the prefix is not an IPv4 address');
}

$tree = '';
for ($i = 0; $i < $nodeCount; ++$i) {
    $bit = ($address >> (31 - $i)) & 1;
    $onPath = $i === $nodeCount - 1 ? $dataPointer : $i + 1;
    $left = 0 === $bit ? $onPath : $noData;
    $right = 1 === $bit ? $onPath : $noData;
    $tree .= pack('N', $left).pack('N', $right);
}

// --- the metadata ----------------------------------------------------------
$metadata = map([
    'node_count' => uint($nodeCount, 6),
    'record_size' => uint(RECORD_SIZE, 6),
    'ip_version' => uint(4, 6),
    'database_type' => utf8('GeoLite2-Country'),
    'languages' => arr([utf8('en')]),
    'binary_format_major_version' => uint(2, 6),
    'binary_format_minor_version' => uint(0, 6),
    'build_epoch' => uint(time(), 9),
    'description' => map(['en' => utf8('Linkboard test country db')]),
]);

$database = $tree.str_repeat("\x00", 16).$data."\xab\xcd\xefMaxMind.com".$metadata;

file_put_contents($out, $database);
printf("wrote %s (%d bytes): %s/%d → %s\n", $out, strlen($database), $prefix, $prefixLength, $country);
