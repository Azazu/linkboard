<?php

declare(strict_types=1);

namespace App\Click;

use App\Link\Entity\Link;

/**
 * The seam between the redirect and the click write path. The synchronous
 * baseline writes to PostgreSQL in the request; the asynchronous change
 * re-implements this with a message carrying the same facts and a Redis
 * counter, without touching the redirect. Implementations throw on
 * infrastructure failure; the caller decides what a failed record means for
 * the response.
 */
interface ClickRecorderInterface
{
    public function record(Link $link, Visit $visit, ClickFacts $facts): RecordOutcome;
}
