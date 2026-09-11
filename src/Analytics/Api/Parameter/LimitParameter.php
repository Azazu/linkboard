<?php

declare(strict_types=1);

namespace App\Analytics\Api\Parameter;

use ApiPlatform\Metadata\QueryParameter;
use App\Analytics\Report\ReportRequest;
use Symfony\Component\Validator\Constraints as Assert;

/** `limit` — how many groups a top-N report returns (1–50, default 10). */
final class LimitParameter extends QueryParameter
{
    public function __construct()
    {
        parent::__construct(
            key: 'limit',
            schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => ReportRequest::MAX_LIMIT, 'default' => ReportRequest::DEFAULT_LIMIT],
            description: \sprintf('Number of groups returned, 1 to %d. Default %d.', ReportRequest::MAX_LIMIT, ReportRequest::DEFAULT_LIMIT),
            constraints: [new Assert\Sequentially([
                new Assert\Regex('/^\d+\z/', message: 'limit must be an integer.'),
                new Assert\Range(min: 1, max: ReportRequest::MAX_LIMIT, notInRangeMessage: 'limit must be between {{ min }} and {{ max }}.'),
            ])],
        );
    }
}
