<?php

declare(strict_types=1);

namespace App\Tests\Api;

use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The document's policy follows the runtime's (change polish-api-and-openapi,
 * design decision 2). The runtime is the authority: these read
 * `config/packages/security.yaml` and the shared parameters exactly as the
 * container resolves them, and assert that the operations documenting 401 and
 * 429 are precisely the ones those rules cover.
 *
 * Gate 2 round 1 found the version that asserted today's literal list instead,
 * which would have gone on passing while the policy moved underneath it.
 */
#[CoversNothing]
final class DocumentedPolicyTest extends WebTestCase
{
    public function testTheOperationsDocumenting401AreThoseTheAccessRulesCover(): void
    {
        $client = self::createClient();
        $public = $this->publicPatterns();
        $token = self::parameter('app.api.path.token');

        $expected = [];
        $documented = [];
        foreach (self::operations($client) as $name => $operation) {
            [, $path] = explode(' ', $name, 2);
            // a firewall refuses an anonymous caller on every guarded path, and
            // the one public path that authenticates refuses bad credentials
            if (!self::matchesAny($path, $public) || $path === $token) {
                $expected[] = $name;
            }
            if (isset($operation['responses']['401'])) {
                $documented[] = $name;
            }
        }
        sort($expected);
        sort($documented);

        self::assertSame($expected, $documented, 'the documented 401 set is the set the access rules produce');
        self::assertNotSame([], $expected, 'the rules resolved to nothing, which means this test stopped working');
    }

    public function testTheOperationsDocumenting429AreThoseTheLimitersCover(): void
    {
        $client = self::createClient();
        $public = $this->publicPatterns();
        /** @var list<string> $unlimited */
        $unlimited = self::parameter('app.api.unlimited_paths');
        /** @var list<array{method: string, pattern: string}> $ipRules */
        $ipRules = self::parameter('app.api.ip_limited_rules');

        $expected = [];
        $documented = [];
        foreach (self::operations($client) as $name => $operation) {
            [$method, $path] = explode(' ', $name, 2);
            $ipLimited = false;
            foreach ($ipRules as $rule) {
                if ($method === $rule['method'] && 1 === preg_match('#'.$rule['pattern'].'#', $path)) {
                    $ipLimited = true;
                }
            }
            $identityLimited = !self::matchesAny($path, $public) && !self::matchesAny($path, $unlimited);
            if ($ipLimited || $identityLimited) {
                $expected[] = $name;
            }
            if (isset($operation['responses']['429'])) {
                $documented[] = $name;
            }
        }
        sort($expected);
        sort($documented);

        self::assertSame($expected, $documented, 'the documented 429 set is the set the two limiters cover');
    }

    public function testTheAllowanceHeadersAreDocumentedOnlyWhereTheirLimiterSendsThem(): void
    {
        $client = self::createClient();
        /** @var list<array{method: string, pattern: string}> $ipRules */
        $ipRules = self::parameter('app.api.ip_limited_rules');

        foreach (self::operations($client) as $name => $operation) {
            [$method, $path] = explode(' ', $name, 2);
            $ipLimited = false;
            foreach ($ipRules as $rule) {
                if ($method === $rule['method'] && 1 === preg_match('#'.$rule['pattern'].'#', $path)) {
                    $ipLimited = true;
                }
            }
            foreach ($operation['responses'] ?? [] as $status => $response) {
                if ((int) $status >= 400) {
                    continue;
                }
                $headers = array_keys($response['headers'] ?? []);
                if ($ipLimited) {
                    self::assertNotContains('X-RateLimit-Remaining', $headers, "$name: the per-address limiter sends no allowance header");
                } else {
                    self::assertContains('X-RateLimit-Remaining', $headers, "$name $status: the per-credential limiter reports its allowance");
                }
            }
        }
    }

    /**
     * The public patterns as the firewall applies them: read from the access
     * rules themselves, with their parameter references resolved.
     *
     * @return list<string>
     */
    private function publicPatterns(): array
    {
        /** @var array{security: array{access_control: list<array{path: string, roles: string}>}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/security.yaml');
        $patterns = [];
        foreach ($config['security']['access_control'] as $rule) {
            if ('PUBLIC_ACCESS' !== $rule['roles']) {
                continue;
            }
            $path = $rule['path'];
            if (1 === preg_match('/^%(.+)%$/', $path, $matches)) {
                $resolved = self::parameter($matches[1]);
                self::assertIsString($resolved, $matches[1].' resolves to a pattern');
                $path = $resolved;
            }
            if (str_starts_with($path, '^/api')) {
                $patterns[] = $path;
            }
        }
        self::assertNotSame([], $patterns);

        return $patterns;
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (1 === preg_match('#'.$pattern.'#', $path)) {
                return true;
            }
        }

        return false;
    }

    private static function parameter(string $name): mixed
    {
        return self::getContainer()->getParameter($name);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function operations(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): array
    {
        $client->request('GET', '/api/docs.json');
        self::assertResponseIsSuccessful();
        /** @var array<string, mixed> $document */
        $document = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $operations = [];
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $document['paths'] ?? [];
        foreach ($paths as $path => $item) {
            foreach ($item as $method => $operation) {
                if (\in_array($method, ['get', 'post', 'patch', 'put', 'delete'], true) && \is_array($operation)) {
                    $operations[strtoupper($method).' '.$path] = $operation;
                }
            }
        }
        ksort($operations);

        return $operations;
    }
}
