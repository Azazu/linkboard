<?php

declare(strict_types=1);

namespace App\Analytics\Api\Parameter;

use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Validator\Constraints as Assert;

/** `includeBots` — bots are stored but excluded from every number unless asked for (FR-CLK-6). */
final class IncludeBotsParameter extends QueryParameter
{
    public function __construct()
    {
        parent::__construct(
            key: 'includeBots',
            schema: ['type' => 'boolean'],
            description: 'Count clicks detected as bots too. Default false.',
            constraints: [new Assert\Choice(choices: ['true', 'false', '1', '0'], message: 'includeBots must be true or false.')],
        );
    }
}
