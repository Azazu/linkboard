<?php

declare(strict_types=1);

namespace App\Link\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * The `rules` member of a link request, read from the raw body with JSON
 * objects preserved (design decision 2): the serializer's associative decode
 * cannot tell `{"0": …}` from a list, so both the validator and the processors
 * take the document from here and never from the DTO's placeholder property.
 */
final readonly class RulesInput
{
    private function __construct(
        public bool $present,
        public mixed $node,
    ) {
    }

    public static function fromRequest(?Request $request): self
    {
        if (null === $request) {
            return new self(false, null);
        }
        $content = $request->getContent();
        if ('' === $content) {
            return new self(false, null);
        }
        try {
            $decoded = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self(false, null); // the framework answers 400 for a body that is not JSON
        }
        if (!$decoded instanceof \stdClass || !property_exists($decoded, 'rules')) {
            return new self(false, null);
        }

        return new self(true, $decoded->rules);
    }
}
