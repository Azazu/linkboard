<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Click\Visit;
use App\Redirect\Detection\DetectedClient;
use App\Redirect\Detection\DeviceDetection;
use App\Redirect\Detection\DeviceDetectionInterface;
use App\Redirect\Geo\CountryResolverInterface;
use App\Redirect\Geo\GeoLite2CountryResolver;
use App\Redirect\Geo\HeaderCountryResolver;
use App\Redirect\VisitFactory;
use App\Redirect\VisitorProfiler;
use App\Tests\Factory\LinkFactory;
use GeoIp2\Exception\AddressNotFoundException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec routing-rules "Hostile input and failures degrade to the default
 * target" and the country-resolution scenarios that need a controlled chain
 * (spoofed header, GeoLite2 missing / not found / reader error). Stubs are
 * installed through the test container before the first request
 * (disableReboot keeps that container for the whole test).
 */
#[CoversNothing]
final class RoutingDegradationTest extends RedirectWebTestCase
{
    private const array VARIANTS = [['name' => 'A', 'weight' => 50, 'target' => 'https://example.com/a'], ['name' => 'B', 'weight' => 50, 'target' => 'https://example.com/b']];

    private TestHandler $log;

    public function testHostileHeadersTakeTheDefaultTargetWithOneNotice(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = $this->languageRuleWithVariants('hostile', utm: ['utm_source' => 'x']);
        $this->captureLog();
        $ua = str_repeat("\xff\xfe", 2048); // 4 KB, invalid UTF-8

        self::visit($client, '/hostile', ['HTTP_USER_AGENT' => $ua, 'HTTP_ACCEPT_LANGUAGE' => str_repeat('de,', 2730), 'HTTP_SEC_CH_UA' => str_repeat('a', 4096)]);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/default?utm_source=x');
        $rows = self::clicksOf($link->getId());
        self::assertCount(1, $rows);
        self::assertSame(['default', null, null, null, null, false], [$rows[0]['resolved_by'], $rows[0]['variant'], $rows[0]['device_type'], $rows[0]['os'], $rows[0]['country'], $rows[0]['is_bot']]);
        $notices = $this->recordsAtNoticeOrAbove();
        self::assertCount(1, $notices);
        self::assertSame(Logger::NOTICE, $notices[0]->level->value);
        self::assertSame((string) $link->getId(), $notices[0]->context['link_id']);
        self::assertSame([VisitFactory::ISSUE_USER_AGENT_OVERSIZED, VisitFactory::ISSUE_ACCEPT_LANGUAGE_OVERSIZED, VisitFactory::ISSUE_CLIENT_HINTS_OVERSIZED], $notices[0]->context['issues']);
        $json = $this->logJson();
        self::assertStringNotContainsString('de,de,', $json);
        self::assertStringNotContainsString(str_repeat('a', 64), $json);
        self::assertStringNotContainsString('203.0.113.7', $json);
    }

    public function testControlCharactersInTheUserAgent(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'control', 'targetUrl' => 'https://example.com/default']);
        $link->replaceRules(['version' => 1, 'rules' => [['match' => ['device' => ['desktop']], 'target' => 'https://example.com/desktop']]], new \DateTimeImmutable());
        self::flush();
        $this->captureLog();

        self::visit($client, '/control', ['HTTP_USER_AGENT' => "Mozilla/5.0 \x00 garbage"]);

        self::assertResponseHeaderSame('Location', 'https://example.com/default');
        $row = self::clicksOf($link->getId())[0];
        self::assertSame(['default', null, null], [$row['resolved_by'], $row['device_type'], $row['os']]);
        $notices = $this->recordsAtNoticeOrAbove();
        self::assertCount(1, $notices);
        self::assertSame([VisitFactory::ISSUE_USER_AGENT_MALFORMED], $notices[0]->context['issues']);
        self::assertSame((string) $link->getId(), $notices[0]->context['link_id']);
    }

    public function testDetectorFailureTakesTheDefaultTargetWithOneNotice(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::new()->withUtm(['utm_source' => 'x'])->create(['slug' => 'detector', 'targetUrl' => 'https://example.com/default']);
        $link->replaceRules(['version' => 1, 'rules' => [['match' => ['device' => ['smartphone']], 'target' => 'https://example.com/m']], 'variants' => self::VARIANTS], new \DateTimeImmutable());
        self::flush();
        self::getContainer()->set(DeviceDetection::class, new class implements DeviceDetectionInterface {
            public function detect(Visit $visit): DetectedClient
            {
                throw new \RuntimeException('regex database corrupt');
            }
        });
        $this->captureLog();

        self::visit($client, '/detector');

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/default?utm_source=x');
        $row = self::clicksOf($link->getId())[0];
        self::assertSame(['default', null, null, null], [$row['resolved_by'], $row['variant'], $row['device_type'], $row['os']]);
        $notices = $this->recordsAtNoticeOrAbove();
        self::assertCount(1, $notices);
        self::assertSame((string) $link->getId(), $notices[0]->context['link_id']);
        self::assertSame(\RuntimeException::class, $notices[0]->context['exception']);
        self::assertStringNotContainsString('203.0.113.7', $this->logJson());
        self::assertStringNotContainsString('Probe/1.0', $this->logJson());
    }

    public function testCountryHeaderIsHonouredOnlyFromATrustedProxy(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'geo-header', 'targetUrl' => 'https://example.com/default']);
        $link->replaceRules(['version' => 1, 'rules' => [['match' => ['country' => ['DE']], 'target' => 'https://example.com/de']]], new \DateTimeImmutable());
        self::flush();
        self::installCountryResolver(new HeaderCountryResolver());

        self::visit($client, '/geo-header', ['HTTP_CF_IPCOUNTRY' => 'DE']); // directly from 203.0.113.7: untrusted
        self::assertResponseHeaderSame('Location', 'https://example.com/default');
        self::visit($client, '/geo-header', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_CF_IPCOUNTRY' => 'DE']);
        self::assertResponseHeaderSame('Location', 'https://example.com/de');

        self::assertSame([null, 'DE'], array_column(self::clicksOf($link->getId()), 'country'));
    }

    public function testGeoLite2DatabaseMissingIsOneWarningAndAnUnknownCountry(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = $this->languageRuleWithVariants('geo-missing');
        $resolverLog = $this->installGeoLite2(static function (): \Closure {
            throw new \InvalidArgumentException('The file "/nowhere/GeoLite2-Country.mmdb" does not exist or is not readable.');
        });
        $this->captureLog();

        self::visit($client, '/geo-missing', ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::assertResponseHeaderSame('Location', 'https://example.com/de');
        self::visit($client, '/geo-missing', ['HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::assertResponseHeaderSame('Location', 'https://example.com/de');

        $rows = self::clicksOf($link->getId());
        self::assertSame(['language', 'language'], array_column($rows, 'resolved_by'));
        self::assertSame([null, null], array_column($rows, 'country'));
        self::assertCount(1, $resolverLog->getRecords());
        self::assertSame(Logger::WARNING, $resolverLog->getRecords()[0]->level->value);
        self::assertSame([], $this->recordsAtNoticeOrAbove(), 'no notice: a missing database is configuration, not a failure');
    }

    public function testAddressNotFoundIsAnUnknownCountryWithoutALogRecord(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = $this->languageRuleWithVariants('geo-notfound');
        $resolverLog = $this->installGeoLite2(static fn (): \Closure => static function (string $ip): ?string {
            throw new AddressNotFoundException('The address '.$ip.' is not in the database.');
        });
        $this->captureLog();

        self::visit($client, '/geo-notfound', ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        self::assertResponseHeaderSame('Location', 'https://example.com/de');
        self::assertNull(self::clicksOf($link->getId())[0]['country']);
        self::assertCount(0, $resolverLog->getRecords());
        self::assertSame([], $this->recordsAtNoticeOrAbove());
    }

    public function testGeoLite2ReaderErrorDegradesTheRedirect(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = $this->languageRuleWithVariants('geo-error', utm: ['utm_source' => 'x']);
        $this->installGeoLite2(static fn (): \Closure => static function (string $ip): ?string {
            throw new \RuntimeException('corrupt database');
        });
        $this->captureLog();

        self::visit($client, '/geo-error', ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://example.com/default?utm_source=x');
        $row = self::clicksOf($link->getId())[0];
        self::assertSame(['default', null, null, null, null], [$row['resolved_by'], $row['variant'], $row['country'], $row['device_type'], $row['os']]);
        $notices = $this->recordsAtNoticeOrAbove();
        self::assertCount(1, $notices);
        self::assertSame((string) $link->getId(), $notices[0]->context['link_id']);
        self::assertSame(\RuntimeException::class, $notices[0]->context['exception']);
        self::assertStringNotContainsString('203.0.113.7', $this->logJson());
    }

    /**
     * @param array<string, string>|null $utm
     */
    private function languageRuleWithVariants(string $slug, ?array $utm = null): \App\Link\Entity\Link
    {
        $factory = null === $utm ? LinkFactory::new() : LinkFactory::new()->withUtm($utm);
        $link = $factory->create(['slug' => $slug, 'targetUrl' => 'https://example.com/default']);
        $link->replaceRules(['version' => 1, 'rules' => [
            ['match' => ['country' => ['DE']], 'target' => 'https://example.com/country'],
            ['match' => ['language' => ['de']], 'target' => 'https://example.com/de'],
        ], 'variants' => self::VARIANTS], new \DateTimeImmutable());
        self::flush();

        return $link;
    }

    /**
     * Installs a GeoLite2 resolver whose reader factory the test controls; returns the handler that receives the resolver's own records.
     *
     * @param \Closure(string): (\Closure(string): ?string) $factory
     */
    private function installGeoLite2(\Closure $factory): TestHandler
    {
        $handler = new TestHandler();
        self::installCountryResolver(new GeoLite2CountryResolver('/nowhere/GeoLite2-Country.mmdb', new Logger('geo', [$handler]), $factory));

        return $handler;
    }

    /**
     * The chain itself is constructed at boot by the startup checks and cannot
     * be replaced in the test container; its only consumer, the profiler, can.
     */
    private static function installCountryResolver(CountryResolverInterface $resolver): void
    {
        $detection = self::getContainer()->get(DeviceDetection::class);
        self::assertInstanceOf(DeviceDetection::class, $detection);
        self::getContainer()->set(VisitorProfiler::class, new VisitorProfiler($detection, $resolver));
    }

    private static function flush(): void
    {
        self::getContainer()->get('doctrine.orm.entity_manager')->flush();
    }

    private function captureLog(): void
    {
        $this->log = new TestHandler();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->log);
    }

    /** @return list<LogRecord> */
    private function recordsAtNoticeOrAbove(): array
    {
        return array_values(array_filter($this->log->getRecords(), static fn (LogRecord $r): bool => $r->level->value >= Logger::NOTICE));
    }

    private function logJson(): string
    {
        return json_encode(array_map(static fn (LogRecord $r): array => $r->toArray(), $this->log->getRecords()), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
