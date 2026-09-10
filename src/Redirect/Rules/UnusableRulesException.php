<?php

declare(strict_types=1);

namespace App\Redirect\Rules;

/**
 * A stored rules document the parser rejects (design decision 6): impossible
 * for documents written through the API, possible after a manual edit or a
 * format change. The redirect guard degrades the request to the default target.
 */
final class UnusableRulesException extends \RuntimeException
{
}
