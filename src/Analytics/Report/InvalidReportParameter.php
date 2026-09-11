<?php

declare(strict_types=1);

namespace App\Analytics\Report;

/**
 * A report query parameter that is malformed or inconsistent with another;
 * `parameter` names the offending one so the API renders a violation on it
 * (spec analytics "Report parameters and period").
 */
final class InvalidReportParameter extends \InvalidArgumentException
{
    public function __construct(public readonly string $parameter, string $message)
    {
        parent::__construct($message);
    }
}
