<?php

declare(strict_types=1);

namespace App\Tests\Unit\Link\UseCase;

use App\Link\UseCase\LinkChanges;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The three states a field can be in (design decision 2 of add-web-ui): left
 * alone, set to a value, or cleared — which null cannot express on its own,
 * because null is a legal new value for four of the six fields.
 */
#[CoversClass(LinkChanges::class)]
final class LinkChangesTest extends TestCase
{
    public function testAFieldNobodyNamedIsLeftAlone(): void
    {
        $changes = new LinkChanges();

        self::assertTrue($changes->isEmpty());
        foreach (['targetUrl', 'utm', 'expiresAt', 'maxClicks', 'rules', 'isActive'] as $field) {
            self::assertFalse($changes->names($field), $field);
        }
    }

    public function testAFieldSetToNullIsClearedAndIsNotTheSameAsAbsent(): void
    {
        $cleared = (new LinkChanges())->withExpiry(null)->withClickLimit(null)->withUtm(null)->withRules(null);

        foreach (['expiresAt', 'maxClicks', 'utm', 'rules'] as $field) {
            self::assertTrue($cleared->names($field), $field);
        }
        self::assertNull($cleared->expiresAt());
        self::assertNull($cleared->maxClicks());
        self::assertNull($cleared->utm());
        self::assertNull($cleared->rules());
        self::assertFalse($cleared->isEmpty());
        self::assertFalse($cleared->names('targetUrl'), 'clearing four fields says nothing about the fifth');
    }

    public function testValuesSurviveUnchanged(): void
    {
        $expiry = new \DateTimeImmutable('2031-01-01T00:00:00+00:00');
        $changes = (new LinkChanges())
            ->withTarget('https://example.com/landing')
            ->withUtm(['utm_source' => 'newsletter'])
            ->withExpiry($expiry)
            ->withClickLimit(25)
            ->withRules(['version' => 1, 'rules' => []])
            ->withActive(false);

        self::assertSame('https://example.com/landing', $changes->targetUrl());
        self::assertSame(['utm_source' => 'newsletter'], $changes->utm());
        self::assertSame($expiry, $changes->expiresAt());
        self::assertSame(25, $changes->maxClicks());
        self::assertSame(['version' => 1, 'rules' => []], $changes->rules());
        self::assertFalse($changes->active());
    }

    public function testEachChangeReturnsANewValueAndLeavesTheOriginalAlone(): void
    {
        $original = new LinkChanges();
        $withTarget = $original->withTarget('https://example.com/');

        self::assertNotSame($original, $withTarget);
        self::assertTrue($original->isEmpty(), 'the original is untouched');
        self::assertTrue($withTarget->names('targetUrl'));
    }

    public function testTheLastValueOfAFieldWins(): void
    {
        $changes = (new LinkChanges())->withClickLimit(5)->withClickLimit(null);

        self::assertTrue($changes->names('maxClicks'));
        self::assertNull($changes->maxClicks(), 'clearing after setting clears');
    }
}
