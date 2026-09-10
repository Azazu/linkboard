<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\TransportInterface;

/** A transport whose stream is down: every send fails (installed as messenger.transport.async). */
final class ThrowingTransport implements TransportInterface
{
    public function get(): iterable
    {
        return [];
    }

    public function ack(Envelope $envelope): void
    {
    }

    public function reject(Envelope $envelope): void
    {
    }

    public function send(Envelope $envelope): Envelope
    {
        throw new TransportException('stream unavailable');
    }
}
