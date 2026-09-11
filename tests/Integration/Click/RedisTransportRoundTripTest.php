<?php

declare(strict_types=1);

namespace App\Tests\Integration\Click;

use App\Click\Message\ClickRecorded;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Bridge\Redis\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransport;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Uid\Uuid;

/**
 * The only test that touches a real Redis stream: the production transport
 * round-trips a ClickRecorded through REDIS_URL on a throw-away stream; every
 * other test uses the in-memory transport (design decision 7).
 */
#[CoversNothing]
final class RedisTransportRoundTripTest extends TestCase
{
    public function testAClickMessageRoundTripsThroughTheStream(): void
    {
        $url = (string) ($_SERVER['REDIS_URL'] ?? $_ENV['REDIS_URL'] ?? '');
        self::assertNotSame('', $url);
        $connection = Connection::fromDsn($url, ['stream' => 'messages_test_'.bin2hex(random_bytes(4)), 'group' => 'test', 'consumer' => 'phpunit']);
        $transport = new RedisTransport($connection, new PhpSerializer());
        $message = new ClickRecorded(Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), new \DateTimeImmutable('2026-09-10T10:00:00+00:00'), 'DE', 'smartphone', 'iOS', 'Mobile Safari', false, 'device', null, 'news.example.org', str_repeat('a', 64));

        try {
            $transport->send(new Envelope($message));
            $received = iterator_to_array($transport->get(), false);

            self::assertCount(1, $received);
            self::assertEquals($message, $received[0]->getMessage());
            $transport->ack($received[0]);
            self::assertSame([], iterator_to_array($transport->get(), false));
        } finally {
            $connection->cleanup();
        }
    }
}
