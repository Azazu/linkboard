<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * NFR-QA-2: an entity depends on `Doctrine\ORM` only through its mapping.
 *
 * Doctrine here is a DataMapper: an entity carries state and intention, never
 * a manager, a repository or a query. An entity that can flush itself is the
 * ActiveRecord this project was written to contrast with.
 *
 * Blind spot: it reads imports and their use. An entity handed a manager as an
 * untyped constructor argument, or reaching one through a static locator,
 * passes this rule.
 */
#[CoversNothing]
final class EntitiesStayMappedTest extends ArchitectureRuleTestCase
{
    /** Everything under Doctrine\ORM except the mapping attributes. */
    private const string MAPPING = 'Doctrine\\ORM\\Mapping';

    public function testAnEntityImportsOnlyItsMapping(): void
    {
        $files = self::filesUnder(self::SRC, static fn (string $path): bool => str_contains($path, '/Entity/'));
        self::assertNotSame([], $files, 'the rule scanned no entity, so it proves nothing');

        $offences = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            if (!preg_match_all('/^use\s+(Doctrine\\\\ORM\\\\[^;\s]+)/m', $source, $matches)) {
                continue;
            }
            foreach ($matches[1] as $import) {
                if (!str_starts_with($import, self::MAPPING)) {
                    $offences[] = self::relative($file).' imports '.$import;
                }
            }
        }

        self::assertSame([], $offences, "an entity may name the ORM only as mapping:\n".implode("\n", $offences));
    }
}
