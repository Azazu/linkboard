<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect\Detection;

use App\Click\Visit;
use App\Redirect\Detection\DetectedClient;
use App\Redirect\Detection\DeviceDetection;
use App\Tests\Fixture\UserAgents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Spec routing-rules "Device, OS, browser and bot detection" over fixed user agents (design decision 7). */
#[CoversClass(DeviceDetection::class)]
#[CoversClass(DetectedClient::class)]
final class DeviceDetectionTest extends TestCase
{
    private static ?DeviceDetection $detection = null;

    /**
     * @return iterable<string, array{string, ?string, ?string, bool}>
     */
    public static function userAgents(): iterable
    {
        yield 'iPhone Safari' => [UserAgents::IPHONE_SAFARI, 'smartphone', 'iOS', false];
        yield 'Android phone Chrome' => [UserAgents::ANDROID_PHONE_CHROME, 'smartphone', 'Android', false];
        yield 'Android tablet Chrome' => [UserAgents::ANDROID_TABLET_CHROME, 'tablet', 'Android', false];
        yield 'iPad Safari' => [UserAgents::IPAD_SAFARI, 'tablet', 'iOS', false];
        yield 'Windows Chrome' => [UserAgents::WINDOWS_CHROME, 'desktop', 'Windows', false];
        yield 'macOS Safari' => [UserAgents::MACOS_SAFARI, 'desktop', 'macOS', false];
        yield 'Linux Firefox' => [UserAgents::LINUX_FIREFOX, 'desktop', 'Linux', false];
        yield 'Googlebot' => [UserAgents::GOOGLEBOT, null, null, true];
        yield 'smart TV' => [UserAgents::SMART_TV, 'other', 'other', false];
        yield 'curl' => [UserAgents::CURL, null, null, false];
        yield 'unknown agent' => [UserAgents::UNKNOWN, null, null, false];
        yield 'empty' => ['', null, null, false];
    }

    #[DataProvider('userAgents')]
    public function testMapping(string $userAgent, ?string $device, ?string $os, bool $isBot): void
    {
        $client = self::detection()->detect(new Visit('203.0.113.7', $userAgent, null, new \DateTimeImmutable()));

        self::assertSame($device, $client->deviceType);
        self::assertSame($os, $client->os);
        self::assertSame($isBot, $client->isBot);
    }

    public function testBrowsersAreNamedAndBounded(): void
    {
        foreach ([UserAgents::IPHONE_SAFARI, UserAgents::ANDROID_PHONE_CHROME, UserAgents::IPAD_SAFARI, UserAgents::WINDOWS_CHROME, UserAgents::MACOS_SAFARI, UserAgents::LINUX_FIREFOX] as $ua) {
            $browser = self::detection()->detect(new Visit('203.0.113.7', $ua, null, new \DateTimeImmutable()))->browser;
            self::assertNotNull($browser, $ua);
            self::assertLessThanOrEqual(DeviceDetection::BROWSER_MAX_LENGTH, mb_strlen($browser), $ua);
        }
        self::assertNull(self::detection()->detect(new Visit('203.0.113.7', UserAgents::CURL, null, new \DateTimeImmutable()))->browser);
    }

    public function testRandomPrintableBytesAreUnknown(): void
    {
        $random = substr(str_repeat(bin2hex(random_bytes(512)), 1), 0, Visit::USER_AGENT_MAX_BYTES);

        $client = self::detection()->detect(new Visit('203.0.113.7', $random, null, new \DateTimeImmutable()));

        self::assertNull($client->deviceType);
        self::assertNull($client->os);
        self::assertFalse($client->isBot);
    }

    public function testTheSameInstanceDetectsTwice(): void
    {
        $detection = self::detection();
        $first = $detection->detect(new Visit('203.0.113.7', UserAgents::IPHONE_SAFARI, null, new \DateTimeImmutable()));
        $second = $detection->detect(new Visit('203.0.113.7', UserAgents::IPHONE_SAFARI, null, new \DateTimeImmutable()));

        self::assertEquals($first, $second);
    }

    /** One in-memory PSR-6 pool for the whole class: the regex database is parsed once, as in production with the filesystem pool. */
    private static function detection(): DeviceDetection
    {
        return self::$detection ??= new DeviceDetection(new ArrayAdapter());
    }
}
