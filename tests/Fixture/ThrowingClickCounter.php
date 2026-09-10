<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Click\Counter\ClickCounterInterface;
use App\Click\RecordOutcome;
use Symfony\Component\Uid\Uuid;

/** A click counter whose store is down (installed through the test container). */
final class ThrowingClickCounter implements ClickCounterInterface
{
    public function increment(Uuid $linkId, int $seed, int $max): RecordOutcome
    {
        throw new \RuntimeException('Click counter unavailable');
    }

    public function forget(Uuid $linkId): void
    {
        throw new \RuntimeException('Click counter unavailable');
    }
}
