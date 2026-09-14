<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Shared\Api\CommonErrorResponses;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The evidence the raised tier asks for (change polish-api-and-openapi,
 * applicability table): this change moved firewall values into shared
 * parameters without changing any of them, so what has to be shown is
 * identity.
 *
 * The values on the right of each assertion are the literals that stood in
 * `config/packages/security.yaml`, `config/routes.yaml` and the two limiter
 * classes before the change, written out here so a later edit to either side
 * has to face them.
 */
#[CoversNothing]
final class MovedPathPolicyTest extends KernelTestCase
{
    public function testEveryMovedValueIsTheLiteralItReplaced(): void
    {
        self::bootKernel();

        self::assertSame('^/api/v1/auth/', self::parameter('app.api.path.auth'));
        self::assertSame('^/api/docs', self::parameter('app.api.path.docs'));
        self::assertSame('^/api/v1$', self::parameter('app.api.path.base'));
        self::assertSame('/api/v1/auth/token', self::parameter('app.api.path.token'));
        self::assertSame(['^/api/v1/auth/', '^/api/docs', '^/api/v1$'], self::parameter('app.api.public_paths'));
        self::assertSame(['^/api/v1/auth/'], self::parameter('app.api.unlimited_paths'));
        self::assertSame([['method' => 'POST', 'pattern' => '^/api/v1/auth/']], self::parameter('app.api.ip_limited_rules'));
    }

    public function testTheFirewallStillCarriesTheSameRules(): void
    {
        self::bootKernel();
        /** @var array{security: array{access_control: list<array{path: string, roles: string}>, firewalls: array{api: array{json_login: array{check_path: string}}}}} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/security.yaml');

        $resolved = array_map(
            static fn (array $rule): array => ['path' => self::resolve($rule['path']), 'roles' => $rule['roles']],
            $config['security']['access_control'],
        );

        // the whole ordered list, because only the first matching rule applies
        self::assertSame([
            ['path' => '^/api/v1/auth/', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/v1/_test/', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/docs', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/v1$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/api/v1/admin/', 'roles' => 'ROLE_ADMIN'],
            ['path' => '^/api/', 'roles' => 'ROLE_USER'],
            ['path' => '^/admin(/|$)', 'roles' => 'ROLE_ADMIN'],
            ['path' => '^/(dashboard|links|api-keys)(/|$)', 'roles' => 'ROLE_USER'],
            ['path' => '^/(login|register)$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/health$', 'roles' => 'PUBLIC_ACCESS'],
            ['path' => '^/$', 'roles' => 'PUBLIC_ACCESS'],
        ], $resolved, 'the access rules resolve to what they were');

        self::assertSame('/api/v1/auth/token', self::resolve($config['security']['firewalls']['api']['json_login']['check_path']));
    }

    public function testTheTokenRouteStillHasItsPath(): void
    {
        self::bootKernel();
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(\Symfony\Component\Routing\RouterInterface::class, $router);
        $route = $router->getRouteCollection()->get('api_auth_token');
        self::assertNotNull($route);

        self::assertSame('/api/v1/auth/token', $route->getPath());
        self::assertSame(['POST'], $route->getMethods());
    }

    public function testTheDecoratorReadsThoseVeryParameters(): void
    {
        self::bootKernel();
        $decorator = self::getContainer()->get('api_platform.openapi.factory');
        self::assertInstanceOf(CommonErrorResponses::class, $decorator);

        foreach (['publicPaths' => 'app.api.public_paths', 'unlimitedPaths' => 'app.api.unlimited_paths', 'ipLimitedRules' => 'app.api.ip_limited_rules'] as $property => $parameter) {
            self::assertSame(
                self::parameter($parameter),
                new \ReflectionProperty(CommonErrorResponses::class, $property)->getValue($decorator),
                "the decorator's $property is the $parameter parameter, not a copy",
            );
        }
    }

    private static function resolve(string $value): string
    {
        if (1 === preg_match('/^%(.+)%$/', $value, $matches)) {
            $resolved = self::parameter($matches[1]);
            self::assertIsString($resolved);

            return $resolved;
        }

        return $value;
    }

    private static function parameter(string $name): mixed
    {
        return self::getContainer()->getParameter($name);
    }
}
