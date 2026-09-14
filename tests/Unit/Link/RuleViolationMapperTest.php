<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link;

use App\Link\Rules\RuleViolation;
use App\Web\Link\RuleViolationMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a rules-document violation lands on the form (design decision 8 of
 * add-web-ui): the parser reports a path into the document, and the structured
 * editor has a field for some of those paths and none for the rest.
 */
#[CoversClass(RuleViolationMapper::class)]
final class RuleViolationMapperTest extends TestCase
{
    /** @return iterable<string, array{string, ?int, ?string}> */
    public static function paths(): iterable
    {
        yield 'a rule target' => ['[rules][0][target]', 0, 'target'];
        yield 'a later rule target' => ['[rules][12][target]', 12, 'target'];
        yield 'a match key' => ['[rules][1][match]', 1, 'values'];
        yield 'a value inside a match' => ['[rules][2][match][device][1]', 2, 'values'];
        yield 'the rule itself' => ['[rules][3]', 3, null];
        yield 'the document root' => ['', null, null];
        yield 'the rules list' => ['[rules]', null, null];
        yield 'a variant' => ['[variants][0][weight]', null, null];
        yield 'the version' => ['[version]', null, null];
    }

    #[DataProvider('paths')]
    public function testAPathIsLocatedOnTheRowAndFieldThatCanHoldIt(string $path, ?int $row, ?string $field): void
    {
        self::assertSame([$row, $field], RuleViolationMapper::locate($path));
    }

    public function testALocatedViolationKeepsItsMessageAndAnUnlocatedOneKeepsItsPath(): void
    {
        $violation = new RuleViolation('[rules][0][target]', 'The target must be an absolute http(s) URL.');

        self::assertSame('The target must be an absolute http(s) URL.', RuleViolationMapper::message($violation, located: true));
        self::assertSame(
            '[rules][0][target] The target must be an absolute http(s) URL.',
            RuleViolationMapper::message($violation, located: false),
            'a message with nowhere to sit says where in the document it belongs',
        );
    }

    public function testAnUnparseablePathIsTreatedAsTheDocumentItself(): void
    {
        // whatever the parser grows next, a path the rows cannot hold must not
        // land on an arbitrary field
        foreach (['rules[0][target]', '[rules][x][target]', '[[rules]]', 'nonsense'] as $path) {
            self::assertSame([null, null], RuleViolationMapper::locate($path), $path);
        }
    }
}
