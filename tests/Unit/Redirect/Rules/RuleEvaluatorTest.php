<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect\Rules;

use App\Click\Visit;
use App\Link\Rules\RulesDocumentParser;
use App\Redirect\Rules\Resolution;
use App\Redirect\Rules\ResolvedBy;
use App\Redirect\Rules\RuleEvaluator;
use App\Redirect\Rules\UnusableRulesException;
use App\Redirect\VisitorProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Spec routing-rules "Fixed matching order", "Absent or unrecognised input is a skipped dimension" (design decisions 5–6). */
#[CoversClass(RuleEvaluator::class)]
#[CoversClass(Resolution::class)]
#[CoversClass(ResolvedBy::class)]
#[CoversClass(UnusableRulesException::class)]
final class RuleEvaluatorTest extends TestCase
{
    private const string DEFAULT = 'https://example.com/default';
    private const array VARIANTS = [['name' => 'A', 'weight' => 50, 'target' => 'https://example.com/a'], ['name' => 'B', 'weight' => 50, 'target' => 'https://example.com/b']];

    public function testDeviceBeatsCountryBeatsLanguageRegardlessOfDocumentOrder(): void
    {
        $rules = ['version' => 1, 'rules' => [
            ['match' => ['language' => ['de']], 'target' => 'https://example.com/lang'],
            ['match' => ['country' => ['DE']], 'target' => 'https://example.com/country'],
            ['match' => ['device' => ['smartphone']], 'target' => 'https://example.com/device'],
        ], 'variants' => self::VARIANTS];

        self::assertResolution('https://example.com/device', ResolvedBy::Device, $this->evaluate($rules, $this->profile('smartphone', 'iOS', 'DE', 'de')));
        self::assertResolution('https://example.com/country', ResolvedBy::Country, $this->evaluate($rules, $this->profile('desktop', 'Windows', 'DE', 'de')));
        self::assertResolution('https://example.com/lang', ResolvedBy::Language, $this->evaluate($rules, $this->profile('desktop', 'Windows', 'FR', 'de')));
        $variant = $this->evaluate($rules, $this->profile('desktop', 'Windows', 'FR', 'en'));
        self::assertSame(ResolvedBy::Variant, $variant->resolvedBy);
        self::assertContains($variant->variant, ['A', 'B']);
    }

    public function testDocumentOrderWithinADimension(): void
    {
        $rules = ['version' => 1, 'rules' => [
            ['match' => ['country' => ['DE', 'FR']], 'target' => 'https://example.com/first'],
            ['match' => ['country' => ['FR']], 'target' => 'https://example.com/second'],
        ]];

        self::assertResolution('https://example.com/first', ResolvedBy::Country, $this->evaluate($rules, $this->profile(country: 'FR')));
    }

    public function testDeviceRuleWithOsRequiresBoth(): void
    {
        $rules = ['version' => 1, 'rules' => [
            ['match' => ['device' => ['smartphone'], 'os' => ['iOS']], 'target' => 'https://example.com/iphone'],
            ['match' => ['os' => ['Android']], 'target' => 'https://example.com/android'],
        ]];

        self::assertResolution('https://example.com/iphone', ResolvedBy::Device, $this->evaluate($rules, $this->profile('smartphone', 'iOS')));
        self::assertResolution('https://example.com/android', ResolvedBy::Device, $this->evaluate($rules, $this->profile('smartphone', 'Android')));
        self::assertResolution('https://example.com/android', ResolvedBy::Device, $this->evaluate($rules, $this->profile('tablet', 'Android')));
        self::assertResolution(self::DEFAULT, ResolvedBy::Default, $this->evaluate($rules, $this->profile('tablet', 'iOS')), 'the iPad falls through');
        self::assertResolution(self::DEFAULT, ResolvedBy::Default, $this->evaluate($rules, $this->profile('smartphone', null)), 'unknown OS skips the os-bearing rules');
    }

    public function testUnresolvedDimensionsAreSkipped(): void
    {
        $rules = ['version' => 1, 'rules' => [
            ['match' => ['device' => ['desktop']], 'target' => 'https://example.com/device'],
            ['match' => ['country' => ['DE']], 'target' => 'https://example.com/country'],
            ['match' => ['language' => ['en']], 'target' => 'https://example.com/lang'],
        ], 'variants' => self::VARIANTS];

        self::assertResolution('https://example.com/lang', ResolvedBy::Language, $this->evaluate($rules, $this->profile(language: 'en')), 'unknown device and country skip to the language rule');
        self::assertSame(ResolvedBy::Variant, $this->evaluate($rules, VisitorProfile::unknown())->resolvedBy, 'everything unknown falls to the variants');
    }

    public function testDefaultsAndVariants(): void
    {
        self::assertResolution(self::DEFAULT, ResolvedBy::Default, $this->evaluate(null, $this->profile('smartphone')), 'no document');
        self::assertResolution(self::DEFAULT, ResolvedBy::Default, $this->evaluate(['version' => 1, 'rules' => [['match' => ['country' => ['DE']], 'target' => 'https://example.com/de']]], $this->profile(country: 'FR')), 'no match, no variants');

        $withVariants = $this->evaluate(['version' => 1, 'variants' => self::VARIANTS], $this->profile('smartphone'));
        self::assertSame(ResolvedBy::Variant, $withVariants->resolvedBy);
        self::assertSame('https://example.com/'.strtolower((string) $withVariants->variant), $withVariants->target);
    }

    public function testUnusableStoredDocumentThrows(): void
    {
        $this->expectException(UnusableRulesException::class);

        $this->evaluate(['version' => 2, 'rules' => 'broken'], $this->profile());
    }

    /**
     * @param array<string, mixed>|null $rules
     */
    private function evaluate(?array $rules, VisitorProfile $profile): Resolution
    {
        return (new RuleEvaluator(new RulesDocumentParser()))->evaluate($rules, $profile, new Visit('203.0.113.7', 'Probe/1.0', null, new \DateTimeImmutable()), Uuid::fromString('0192b6f0-0000-7000-8000-000000000001'), self::DEFAULT);
    }

    private function profile(?string $device = null, ?string $os = null, ?string $country = null, ?string $language = null): VisitorProfile
    {
        return new VisitorProfile($device, $os, 'Browser', false, $country, $language);
    }

    private static function assertResolution(string $target, ResolvedBy $by, Resolution $actual, string $message = ''): void
    {
        self::assertSame($target, $actual->target, $message);
        self::assertSame($by, $actual->resolvedBy, $message);
        self::assertNull($actual->variant, $message);
    }
}
