<?php

declare(strict_types=1);

namespace App\Redirect;

enum RedirectStatus
{
    /** unknown slug or inactive link → 404 */
    case NotFound;
    /** expired or click limit reached → 410 */
    case Gone;
    /** the stores failed in a way that forbids an answer → 503 */
    case Unavailable;
    /** 302 to `location` */
    case Redirect;
}
