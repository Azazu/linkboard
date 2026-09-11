<?php

declare(strict_types=1);

namespace App\Click\Counter;

use App\Click\RecordOutcome;
use Symfony\Component\Uid\Uuid;

/**
 * FR-RED-3: the authority for a link's click limit at redirect time. One call
 * per accepted redirect of a limited link; links without a limit never call it.
 * Implementations throw on infrastructure failure — the redirect answers 503
 * for a limited link in that case (spec redirect, "Failures of the stores").
 */
interface ClickCounterInterface
{
    /**
     * @param int $seed the link's persisted click_count: the counter is lifted to it when absent or lower
     * @param int $max  the link's max_clicks
     *
     * @throws \RuntimeException when the counter store cannot answer
     */
    public function increment(Uuid $linkId, int $seed, int $max): RecordOutcome;

    /**
     * Removes the link's counter (deletion of the link).
     *
     * @throws \RuntimeException when the counter store cannot answer
     */
    public function forget(Uuid $linkId): void;
}
