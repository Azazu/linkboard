<?php

declare(strict_types=1);

namespace App\Click;

enum RecordOutcome
{
    /** The click was counted and stored; the redirect may proceed. */
    case Allowed;
    /** The link's click limit was already reached; nothing was stored. */
    case Exhausted;
}
