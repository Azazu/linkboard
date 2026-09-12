<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * The three answers of the database phase (design decision 3): the letters are
 * the child process's output protocol.
 */
enum LookupStatus: string
{
    case Verified = 'V';
    case Denied = 'D';
    case Unavailable = 'U';
}
