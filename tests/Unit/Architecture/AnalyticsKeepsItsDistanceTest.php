<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * NFR-QA-2: the analytics read model names no `Click` entity.
 *
 * This is the boundary the whole project is arranged by (CQRS-lite, ADR-002):
 * the click write path persists entities, the read path computes from SQL and
 * returns immutable DTOs. The moment a query service loads a `Click` the two
 * share a model again and every report becomes a lazy-loading surprise waiting
 * to happen.
 *
 * Blind spot: a text scan sees text. `$class = 'App\Click\Entity\Click'` or a
 * repository fetched through a collaborator passes this rule.
 */
#[CoversNothing]
final class AnalyticsKeepsItsDistanceTest extends ArchitectureRuleTestCase
{
    public function testNoAnalyticsFileNamesTheClickEntity(): void
    {
        $files = self::filesUnder(self::SRC.'/Analytics');
        self::assertNotSame([], $files, 'the rule scanned no file, so it proves nothing');

        $offences = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['App\\Click\\Entity\\', 'Click::class'] as $forbidden) {
                if (str_contains($source, $forbidden)) {
                    $offences[] = self::relative($file).' names '.$forbidden;
                }
            }
        }

        self::assertSame([], $offences, "the analytics read model must not reach for a click entity:\n".implode("\n", $offences));
    }
}
