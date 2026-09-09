<?php

declare(strict_types=1);

namespace App\Tests\Integration\Link;

use App\Link\ReservedSlugs;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * FR-LNK-3: every top-level path segment the application routes must be a
 * reserved slug, otherwise a link could shadow a page. Compared against the
 * live router so that adding a top-level route without reserving it fails here.
 */
#[CoversClass(ReservedSlugs::class)]
final class ReservedSlugsCoverRoutesTest extends KernelTestCase
{
    public function testEveryTopLevelRouteSegmentIsReserved(): void
    {
        self::bootKernel();
        $routes = self::getContainer()->get(RouterInterface::class)->getRouteCollection();

        self::assertSame([], self::unreservedSegments($routes), 'top-level route segments missing from ReservedSlugs::LIST');
    }

    public function testAnUnreservedTopLevelRouteIsDetected(): void
    {
        // failing input for the guard: a page nobody reserved
        $routes = new RouteCollection();
        $routes->add('new_page', new Route('/newpage'));
        $routes->add('param_root', new Route('/{slug}'));

        self::assertSame(['newpage'], self::unreservedSegments($routes));
    }

    /**
     * @return list<string>
     */
    private static function unreservedSegments(RouteCollection $routes): array
    {
        $missing = [];
        foreach ($routes as $route) {
            $segment = explode('/', ltrim($route->getPath(), '/'), 2)[0];
            $segment = preg_replace('/\..*$/', '', $segment) ?? $segment; // "/docs.{_format}" → "docs"
            if ('' === $segment || str_starts_with($segment, '{')) {
                continue; // the root page and parameterized first segments (the redirect route itself)
            }
            if (!ReservedSlugs::contains($segment)) {
                $missing[$segment] = $segment;
            }
        }

        return array_values($missing);
    }
}
