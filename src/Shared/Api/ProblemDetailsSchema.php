<?php

declare(strict_types=1);

namespace App\Shared\Api;

/**
 * The RFC 9457 shape the `api-error-format` capability requires, as an OpenAPI
 * schema and as an example — one definition, so what the document promises and
 * what `ProblemDetails` sends cannot describe different things.
 */
final class ProblemDetailsSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function schema(bool $withViolations = false): array
    {
        $schema = [
            'type' => 'object',
            'description' => 'RFC 9457 problem details.',
            'properties' => [
                'type' => ['type' => 'string', 'description' => 'A URI reference identifying the problem type.', 'example' => '/errors/404'],
                'title' => ['type' => 'string', 'description' => 'A short, human-readable summary of the problem type.', 'example' => 'Not Found'],
                'status' => ['type' => 'integer', 'description' => 'The HTTP status code.', 'example' => 404],
                'detail' => ['type' => 'string', 'description' => 'A human-readable explanation of this occurrence.', 'example' => 'No such link.'],
            ],
            'required' => ['type', 'title', 'status', 'detail'],
        ];

        if ($withViolations) {
            $schema['properties']['violations'] = [
                'type' => 'array',
                'description' => 'One element per rejected value.',
                'example' => [['propertyPath' => 'targetUrl', 'message' => 'The target must be an absolute http(s) URL.']],
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'propertyPath' => ['type' => 'string', 'description' => 'The path of the rejected value inside the payload.', 'example' => 'targetUrl'],
                        'message' => ['type' => 'string', 'description' => 'What is wrong with it.', 'example' => 'The target must be an absolute http(s) URL.'],
                        'code' => ['type' => 'string', 'description' => 'The constraint identifier, when the constraint provides one.', 'example' => 'c1051bb4-d103-4f74-8988-acbcafc7fdc3'],
                    ],
                    'required' => ['propertyPath', 'message'],
                ],
            ];
            $schema['required'][] = 'violations';
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(int $status, string $title, string $detail): array
    {
        return ['type' => '/errors/'.$status, 'title' => $title, 'status' => $status, 'detail' => $detail];
    }

    private function __construct()
    {
    }
}
