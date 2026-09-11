<?php

declare(strict_types=1);

namespace App\Analytics\Query;

use App\Analytics\Report\ReportRequest;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;

/**
 * Shared fragments of the report statements (design decision 2). The bot
 * condition is a literal chosen in PHP, never a bound boolean: a bound value
 * hides `NOT is_bot` from the planner once a generic plan is used, and the
 * partial index `idx_clicks_link_occurred_human` exists for exactly that
 * predicate. Nothing user-supplied is ever concatenated — the request's
 * values travel as bound parameters.
 */
final class Sql
{
    private function __construct()
    {
    }

    /** `AND NOT is_bot`, or nothing when bots are included. */
    public static function bots(ReportRequest $request): string
    {
        return $request->includeBots ? '' : ' AND NOT is_bot';
    }

    /** `AND link_id = :link_id` for a link report, nothing for a global one. */
    public static function link(ReportRequest $request): string
    {
        return null === $request->linkId ? '' : ' AND link_id = :link_id';
    }

    /**
     * @return array<string, mixed>
     */
    public static function params(ReportRequest $request): array
    {
        $params = [
            'from' => $request->period->from,
            'to' => $request->period->to,
        ];
        if (null !== $request->linkId) {
            $params['link_id'] = $request->linkId->toRfc4122();
        }

        return $params;
    }

    /**
     * @return array<string, string|ParameterType>
     */
    public static function types(): array
    {
        return [
            'from' => Types::DATETIMETZ_IMMUTABLE,
            'to' => Types::DATETIMETZ_IMMUTABLE,
            'prev_from' => Types::DATETIMETZ_IMMUTABLE,
            'today' => Types::DATETIMETZ_IMMUTABLE,
            'limit' => ParameterType::INTEGER,
        ];
    }

    public static function utc(string $timestamp): \DateTimeImmutable
    {
        return new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
    }
}
