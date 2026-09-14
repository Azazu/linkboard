<?php

declare(strict_types=1);

namespace App\Shared\Api;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What every operation can answer but no attribute says (change
 * polish-api-and-openapi, design decision 1): a firewalled path can refuse an
 * anonymous caller with 401, a rate-limited one can refuse with 429, and any
 * operation can refuse a media type it does not produce with 406. Those rules
 * are global — they live in the firewall and the limiter, not in an operation
 * — so they are applied here once instead of being restated on twenty-five
 * operations, where the first firewall change would leave them stale.
 *
 * The same pass makes every error response say what the API actually sends:
 * `application/problem+json` alone, with the RFC 9457 schema and an example.
 * It only adds statuses and narrows media types; nothing the inner factory
 * produced is dropped, which `OpenApiDocumentTest` asserts against the
 * undecorated document.
 *
 * The path patterns come from `config/services.yaml`, where `security.yaml`
 * and `ApiRateLimitListener` read the same values.
 */
// the lowest priority is the outermost decorator, so this one sees what the
// JWT bundle's factory adds — the token operation included
#[AsDecorator('api_platform.openapi.factory', priority: -100)]
final readonly class CommonErrorResponses implements OpenApiFactoryInterface
{
    private const array METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private const array RETRY_AFTER = [
        'Retry-After' => ['description' => 'Seconds to wait before retrying.', 'schema' => ['type' => 'integer', 'example' => 30]],
    ];

    private const array ALLOWANCE_SPENT = [
        'X-RateLimit-Limit' => ['description' => 'Requests allowed per window.', 'schema' => ['type' => 'integer', 'example' => 600]],
        'X-RateLimit-Remaining' => ['description' => 'Requests left in the current window.', 'schema' => ['type' => 'integer', 'example' => 0]],
    ];

    private const array ALLOWANCE_LEFT = [
        'X-RateLimit-Limit' => ['description' => 'Requests allowed per window.', 'schema' => ['type' => 'integer', 'example' => 600]],
        'X-RateLimit-Remaining' => ['description' => 'Requests left in the current window.', 'schema' => ['type' => 'integer', 'example' => 599]],
    ];

    private const array TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        422 => 'Unprocessable Content',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
    ];

    /**
     * @param list<string>                                 $publicPaths      anchored patterns of paths that need no credential
     * @param list<string>                                 $unlimitedPaths   anchored patterns the API identity limiter does not count
     * @param list<array{method: string, pattern: string}> $ipLimitedRules   what the per-IP auth limiter guards, method included
     * @param string                                       $authenticatePath the one public path that authenticates, so it answers 401 itself
     */
    public function __construct(
        private OpenApiFactoryInterface $inner,
        #[Autowire('%app.api.public_paths%')]
        private array $publicPaths = [],
        #[Autowire('%app.api.unlimited_paths%')]
        private array $unlimitedPaths = [],
        #[Autowire('%app.api.ip_limited_rules%')]
        private array $ipLimitedRules = [],
        #[Autowire('%app.api.path.token%')]
        private string $authenticatePath = '',
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->inner)($context);
        $paths = $openApi->getPaths();

        foreach ($paths->getPaths() as $path => $item) {
            $paths->addPath($path, $this->decoratePath($path, $item));
        }

        return $openApi->withPaths($paths);
    }

    private function decoratePath(string $path, PathItem $item): PathItem
    {
        foreach (self::METHODS as $method) {
            $operation = $item->{'get'.ucfirst($method)}();
            if ($operation instanceof Operation) {
                $item = $item->{'with'.ucfirst($method)}($this->decorateOperation($path, strtoupper($method), $operation));
            }
        }

        return $item;
    }

    private function decorateOperation(string $path, string $method, Operation $operation): Operation
    {
        $responses = $operation->getResponses() ?? [];

        // every declared failure says what the API really sends, and nothing else
        foreach ($responses as $status => $response) {
            if ((int) $status >= 400 && $response instanceof Response) {
                $responses[$status] = self::problem(
                    $response->getDescription() ?? self::title((int) $status),
                    (int) $status,
                    422 === (int) $status,
                    (array) ($response->getHeaders() ?? new \ArrayObject()),
                );
            }
        }

        // what the path implies, wherever the operation did not already say it
        if ($this->refusesCredentials($path)) {
            $responses[401] ??= self::problem(
                $path === $this->authenticatePath
                    ? 'The credentials are not those of an account that can sign in.'
                    : 'No credential was presented, or it is unknown, expired or revoked.',
                401,
            );
        }
        // both limiters name a delay when they refuse; only the per-identity
        // one reports the remaining allowance on the responses it lets through
        if ($this->isLimited($path, $method)) {
            $perIdentity = !$this->isIpLimited($path, $method);
            $responses[429] ??= self::problem(
                $perIdentity
                    ? 'The rate limit for this credential is exhausted. Retry after the delay the header names.'
                    : 'Too many attempts from this address. Retry after the delay the header names.',
                429,
                headers: $perIdentity ? self::RETRY_AFTER + self::ALLOWANCE_SPENT : self::RETRY_AFTER,
            );
            if ($perIdentity) {
                foreach ($responses as $status => $response) {
                    if ((int) $status < 400 && $response instanceof Response) {
                        $responses[$status] = $response->withHeaders(new \ArrayObject(
                            (array) ($response->getHeaders() ?? new \ArrayObject()) + self::ALLOWANCE_LEFT,
                        ));
                    }
                }
            }
        }
        if ($path === $this->authenticatePath) {
            $operation = self::withCredentialBody($operation);
            $responses[200] = self::tokenResponse($responses[200] ?? null);
            // what json_login answers, which no state processor declares
            // (design decision 2a): a payload it cannot read, and an account
            // the user checker refuses
            $responses[400] ??= self::problem('The credential payload is not valid JSON, or does not carry both members.', 400);
            $responses[403] ??= self::problem('The account is blocked.', 403);
        }
        if ($path !== $this->authenticatePath) {
            // the authentication endpoint replies before content negotiation
            // runs, so it cannot refuse an Accept header (Gate 2 round 1,
            // finding 3); every other operation can
            $responses[406] ??= self::problem('The requested media type is not one this operation produces.', 406);
        }
        if (null !== $operation->getRequestBody()) {
            $responses[415] ??= self::problem('The request body is not in a media type this operation accepts.', 415);
        }

        ksort($responses);

        return $operation->withResponses($responses);
    }

    /**
     * The authentication operation's payloads come from the JWT bundle, which
     * generates them inline: there is no property of ours to annotate, so the
     * examples — and the `expiresAt` the success response actually sends —
     * are described here (Gate 2 round 1, finding 4).
     */
    private static function withCredentialBody(Operation $operation): Operation
    {
        $body = $operation->getRequestBody();
        if (null !== $body) {
            $operation = $operation->withRequestBody($body->withContent(new \ArrayObject([
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'email' => ['type' => 'string', 'format' => 'email', 'description' => 'The account\'s address.', 'example' => 'ada@example.com'],
                            'password' => ['type' => 'string', 'format' => 'password', 'description' => 'Its password.', 'example' => 'correct-horse-battery-staple'],
                        ],
                        'required' => ['email', 'password'],
                    ],
                    'example' => ['email' => 'ada@example.com', 'password' => 'correct-horse-battery-staple'],
                ],
            ])));
        }

        return $operation;
    }

    private static function tokenResponse(mixed $success): Response
    {
        return new Response(
            $success instanceof Response ? ($success->getDescription() ?? 'A token for the account.') : 'A token for the account.',
            new \ArrayObject([
                'application/json' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'token' => ['type' => 'string', 'description' => 'The bearer token to present on later requests.', 'example' => 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhZGFAZXhhbXBsZS5jb20ifQ.signature'],
                            'expiresAt' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When it stops being accepted.', 'example' => '2026-09-14T10:30:00+00:00'],
                        ],
                        'required' => ['token', 'expiresAt'],
                    ],
                    'example' => ['token' => 'eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJhZGFAZXhhbXBsZS5jb20ifQ.signature', 'expiresAt' => '2026-09-14T10:30:00+00:00'],
                ],
            ]),
            $success instanceof Response ? $success->getHeaders() : null,
        );
    }

    private function isPublic(string $path): bool
    {
        return self::matches($path, $this->publicPaths);
    }

    /**
     * A firewall refuses an anonymous caller on every guarded path; the one
     * public path that authenticates refuses bad credentials itself.
     */
    private function refusesCredentials(string $path): bool
    {
        return !$this->isPublic($path) || $path === $this->authenticatePath;
    }

    /**
     * Either limiter can refuse: the per-identity one on the authenticated
     * operations, the per-IP one on the authentication endpoints — and that
     * one only on the method it guards.
     */
    private function isLimited(string $path, string $method): bool
    {
        if ($this->isIpLimited($path, $method)) {
            return true;
        }

        return !$this->isPublic($path) && !self::matches($path, $this->unlimitedPaths);
    }

    private function isIpLimited(string $path, string $method): bool
    {
        foreach ($this->ipLimitedRules as $rule) {
            if ($method === $rule['method'] && 1 === preg_match('#'.$rule['pattern'].'#', $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private static function matches(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (1 === preg_match('#'.$pattern.'#', $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $headers
     */
    private static function problem(string $description, int $status, bool $withViolations = false, array $headers = []): Response
    {
        return new Response(
            $description,
            new \ArrayObject([
                'application/problem+json' => [
                    'schema' => ProblemDetailsSchema::schema($withViolations),
                    'example' => $withViolations
                        ? ProblemDetailsSchema::example($status, self::title($status), $description) + ['violations' => [['propertyPath' => 'targetUrl', 'message' => 'The target must be an absolute http(s) URL.']]]
                        : ProblemDetailsSchema::example($status, self::title($status), $description),
                ],
            ]),
            [] === $headers ? null : new \ArrayObject($headers),
        );
    }

    private static function title(int $status): string
    {
        return self::TITLES[$status] ?? 'Error';
    }
}
