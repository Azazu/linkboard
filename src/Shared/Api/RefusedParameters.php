<?php

declare(strict_types=1);

namespace App\Shared\Api;

/**
 * What an operation says when a query parameter is refused, written once for
 * the operations that share the answer (change polish-api-and-openapi, Gate 2
 * round 2, finding 1).
 *
 * Which status an operation uses is the operation's own business, not its
 * path's, so each declares the one it sends: the collections are refused by
 * the framework's parameter validation with 400, the analytics reports by
 * their own parameter rules with 422 and a violation per refused value.
 * `CommonErrorResponses` then gives both the problem-details shape.
 *
 * These are constants rather than factories because an attribute argument has
 * to be a constant expression.
 *
 * `OpenApiDocumentTest` asserts that every operation declaring query
 * parameters declares one of these statuses, so an operation added later
 * cannot take a parameter it never says it can refuse.
 */
final class RefusedParameters
{
    public const string BAD_REQUEST = 'A query parameter carries a value its type or range does not admit.';

    public const string UNPROCESSABLE = 'A report parameter is refused: a bound that is not an RFC 3339 timestamp, a period that is inverted or longer than the capability allows, an hourly granularity over too long a period, a limit outside 1–50, or a bots flag that is not a boolean. One violation per refused parameter.';

    private function __construct()
    {
    }
}
