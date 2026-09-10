<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Click\Counter\ClickCounterInterface;
use App\Click\RecordOutcome;
use Symfony\Component\Uid\Uuid;

/** Counts the calls to a real counter (how many Redis commands a redirect issued). */
final class CountingClickCounter implements ClickCounterInterface
{
    public int $increments = 0;
    public int $forgets = 0;

    public function __construct(private readonly ClickCounterInterface $inner)
    {
    }

    public function increment(Uuid $linkId, int $seed, int $max): RecordOutcome
    {
        ++$this->increments;

        return $this->inner->increment($linkId, $seed, $max);
    }

    public function forget(Uuid $linkId): void
    {
        ++$this->forgets;
        $this->inner->forget($linkId);
    }
}
