<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * NFR-QA-2: a controller builds no query.
 *
 * Controllers are thin here: they take a request, ask a service or a
 * repository, and render. A query built in a controller is a use case with
 * nowhere to be tested and nowhere to be reused, which is how the API and the
 * pages drift apart.
 *
 * Two things this rule deliberately does NOT forbid, and one it cannot see:
 * `EntityManagerInterface::flush()` is a unit-of-work commit, not a query, and
 * `App\Auth\Api\RegistrationInput`-style form handling is not one either. What
 * it cannot see is a query built inside a service the controller calls — which
 * is exactly where a query belongs.
 */
#[CoversNothing]
final class ControllersDoNotQueryTest extends ArchitectureRuleTestCase
{
    /** @var list<string> */
    private const array QUERY_SHAPES = [
        'createQueryBuilder',
        'createQuery(',
        'createNativeQuery',
        'getRepository(',
        'executeQuery(',
        'executeStatement(',
        'SELECT ',
        'INSERT INTO',
        'UPDATE ',
        'DELETE FROM',
    ];

    public function testNoControllerBuildsAQuery(): void
    {
        $files = self::filesUnder(self::SRC, static fn (string $path): bool => str_ends_with($path, 'Controller.php'));
        self::assertNotSame([], $files, 'the rule scanned no controller, so it proves nothing');

        $offences = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (self::QUERY_SHAPES as $shape) {
                if (str_contains($source, $shape)) {
                    $offences[] = self::relative($file).' contains '.trim($shape);
                }
            }
        }

        self::assertSame([], $offences, "a controller asks a service; it does not query:\n".implode("\n", $offences));
    }
}
