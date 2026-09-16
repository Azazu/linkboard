<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Link;

use App\Web\Link\RulesDocumentMapper;
use App\Web\Link\RulesFormData;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The editor's view of a stored document, and what it does with a document
 * the rows cannot hold.
 *
 * The raw fallback is the point: the mapper's own docblock says dropping half
 * a document is worse than a textarea, and a match whose values are not a list
 * of strings used to produce a row with empty values — which the next save
 * would have written back over the link's rules (change harden-gate-floor).
 */
#[CoversClass(RulesDocumentMapper::class)]
final class RulesDocumentMapperTest extends TestCase
{
    public function testACanonicalDocumentBecomesRows(): void
    {
        $data = RulesDocumentMapper::toForm([
            'rules' => [
                ['match' => ['country' => ['DE', 'AT']], 'target' => 'https://example.com/de'],
            ],
        ]);

        self::assertSame(RulesFormData::MODE_STRUCTURED, $data->mode);
        self::assertCount(1, $data->rows);
        self::assertSame('country', $data->rows[0]->matchKey);
        self::assertSame('DE, AT', $data->rows[0]->values);
        self::assertSame('https://example.com/de', $data->rows[0]->target);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function notRepresentable(): iterable
    {
        yield 'a match value that is a bare string, not a list' => [
            ['rules' => [['match' => ['country' => 'DE'], 'target' => 'https://example.com/de']]],
        ];
        yield 'a match value holding a number' => [
            ['rules' => [['match' => ['country' => ['DE', 49]], 'target' => 'https://example.com/de']]],
        ];
        yield 'a match value holding a nested list' => [
            ['rules' => [['match' => ['country' => [['DE']]], 'target' => 'https://example.com/de']]],
        ];
        yield 'a match value that is a map, not a list' => [
            ['rules' => [['match' => ['country' => ['first' => 'DE']], 'target' => 'https://example.com/de']]],
        ];
        yield 'a match key that is not a string' => [
            ['rules' => [['match' => [0 => ['DE']], 'target' => 'https://example.com/de']]],
        ];
    }

    /**
     * @param array<string, mixed> $stored
     */
    #[DataProvider('notRepresentable')]
    public function testADocumentTheRowsCannotHoldIsShownRawWithNoRows(array $stored): void
    {
        $data = RulesDocumentMapper::toForm($stored);

        self::assertSame(RulesFormData::MODE_RAW, $data->mode);
        self::assertSame([], $data->rows, 'no half-filled row can be saved back over the document');
        self::assertJson((string) $data->raw, 'the raw view still carries the document verbatim');
    }
}
