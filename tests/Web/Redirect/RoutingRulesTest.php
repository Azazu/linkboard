<?php

declare(strict_types=1);

namespace App\Tests\Web\Redirect;

use App\Tests\Factory\LinkFactory;
use App\Tests\Fixture\UserAgents;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec routing-rules requirements 3–8 over HTTP with real detection, the
 * fixed IP→country map (198.51.100.7 DE, .44 US, .33 FR) and Accept-Language;
 * spec click-logging "Resolution recorded" and "Plain link"; spec redirect
 * "UTM on a rule target".
 */
#[CoversNothing]
final class RoutingRulesTest extends RedirectWebTestCase
{
    private const string DE = '198.51.100.7';
    private const string FR = '198.51.100.33';
    private const string UNMAPPED = '203.0.113.7';
    private const array VARIANTS = [['name' => 'A', 'weight' => 50, 'target' => 'https://example.com/a'], ['name' => 'B', 'weight' => 50, 'target' => 'https://example.com/b']];

    private TestHandler $log;

    public function testDeviceBeatsCountryBeatsLanguageThenVariants(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'matrix', 'targetUrl' => 'https://example.com/default']);
        $link->replaceRules(['version' => 1, 'rules' => [
            ['match' => ['language' => ['de']], 'target' => 'https://example.com/lang'],
            ['match' => ['country' => ['DE']], 'target' => 'https://example.com/country'],
            ['match' => ['device' => ['smartphone']], 'target' => 'https://example.com/device'],
        ], 'variants' => self::VARIANTS], new \DateTimeImmutable());
        self::flush();

        self::visit($client, '/matrix', ['HTTP_USER_AGENT' => UserAgents::IPHONE_SAFARI, 'REMOTE_ADDR' => self::DE, 'HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::assertResponseHeaderSame('Location', 'https://example.com/device');
        self::visit($client, '/matrix', ['HTTP_USER_AGENT' => UserAgents::WINDOWS_CHROME, 'REMOTE_ADDR' => self::DE, 'HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::assertResponseHeaderSame('Location', 'https://example.com/country');
        self::visit($client, '/matrix', ['HTTP_USER_AGENT' => UserAgents::WINDOWS_CHROME, 'REMOTE_ADDR' => self::FR, 'HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::assertResponseHeaderSame('Location', 'https://example.com/lang');
        self::visit($client, '/matrix', ['HTTP_USER_AGENT' => UserAgents::WINDOWS_CHROME, 'REMOTE_ADDR' => self::FR, 'HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertContains($client->getResponse()->headers->get('Location'), ['https://example.com/a', 'https://example.com/b']);

        $rows = self::clicksOf($link->getId());
        self::assertSame(['device', 'country', 'language', 'variant'], array_column($rows, 'resolved_by'));
        self::assertSame([null, null, null], array_column(\array_slice($rows, 0, 3), 'variant'));
        self::assertContains($rows[3]['variant'], ['A', 'B']);
    }

    public function testDocumentOrderWithinADimension(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'order']);
        $link->replaceRules(['version' => 1, 'rules' => [
            ['match' => ['country' => ['DE', 'FR']], 'target' => 'https://example.com/first'],
            ['match' => ['country' => ['FR']], 'target' => 'https://example.com/second'],
        ]], new \DateTimeImmutable());
        self::flush();

        self::visit($client, '/order', ['REMOTE_ADDR' => self::FR]);

        self::assertResponseHeaderSame('Location', 'https://example.com/first');
    }

    public function testDeviceRuleWithAnOsCondition(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'apps', 'targetUrl' => 'https://example.com/default']);
        $link->replaceRules(['version' => 1, 'rules' => [
            ['match' => ['device' => ['smartphone'], 'os' => ['iOS']], 'target' => 'https://example.com/iphone'],
            ['match' => ['os' => ['Android']], 'target' => 'https://example.com/android'],
        ]], new \DateTimeImmutable());
        self::flush();

        $locations = [];
        foreach ([UserAgents::IPHONE_SAFARI, UserAgents::ANDROID_PHONE_CHROME, UserAgents::ANDROID_TABLET_CHROME, UserAgents::IPAD_SAFARI] as $ua) {
            self::visit($client, '/apps', ['HTTP_USER_AGENT' => $ua]);
            $locations[] = $client->getResponse()->headers->get('Location');
        }

        self::assertSame(['https://example.com/iphone', 'https://example.com/android', 'https://example.com/android', 'https://example.com/default'], $locations, 'the iPad falls through to targetUrl');
        self::assertSame(['device', 'device', 'device', 'default'], array_column(self::clicksOf($link->getId()), 'resolved_by'));
    }

    public function testDefaultWhenNothingMatchesAndUnknownCountrySkips(): void
    {
        $client = self::createClient();
        $noMatch = LinkFactory::new()->withUtm(['utm_source' => 'x'])->create(['slug' => 'nomatch', 'targetUrl' => 'https://example.com/default']);
        $noMatch->replaceRules(['version' => 1, 'rules' => [['match' => ['country' => ['DE']], 'target' => 'https://example.com/de']]], new \DateTimeImmutable());
        $skip = LinkFactory::createOne(['slug' => 'skip']);
        $skip->replaceRules(['version' => 1, 'rules' => [
            ['match' => ['country' => ['DE']], 'target' => 'https://example.com/de'],
            ['match' => ['language' => ['en']], 'target' => 'https://example.com/en'],
        ]], new \DateTimeImmutable());
        self::flush();

        self::visit($client, '/nomatch', ['REMOTE_ADDR' => self::UNMAPPED]);
        self::assertResponseHeaderSame('Location', 'https://example.com/default?utm_source=x');
        self::assertSame('default', self::clicksOf($noMatch->getId())[0]['resolved_by']);

        self::visit($client, '/skip', ['REMOTE_ADDR' => self::UNMAPPED, 'HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseHeaderSame('Location', 'https://example.com/en');
    }

    public function testMissingHeadersFallThroughToTheVariantsSilently(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'silent']);
        $link->replaceRules(['version' => 1, 'rules' => [
            ['match' => ['device' => ['desktop']], 'target' => 'https://example.com/device'],
            ['match' => ['country' => ['DE']], 'target' => 'https://example.com/country'],
            ['match' => ['language' => ['en']], 'target' => 'https://example.com/lang'],
        ], 'variants' => self::VARIANTS], new \DateTimeImmutable());
        self::flush();
        $this->captureLog();

        self::visit($client, '/silent', ['HTTP_USER_AGENT' => '', 'HTTP_ACCEPT_LANGUAGE' => '', 'REMOTE_ADDR' => self::UNMAPPED]); // BrowserKit supplies en-us otherwise

        self::assertResponseStatusCodeSame(302);
        self::assertContains($client->getResponse()->headers->get('Location'), ['https://example.com/a', 'https://example.com/b']);
        $row = self::clicksOf($link->getId())[0];
        self::assertSame('variant', $row['resolved_by']);
        self::assertSame([null, null, null], [$row['device_type'], $row['os'], $row['country']]);
        self::assertSame([], $this->recordsAtNoticeOrAbove());
    }

    public function testWellFormedUnknownInputsAreSilent(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $link = LinkFactory::createOne(['slug' => 'unknown', 'targetUrl' => 'https://example.com/default']);
        $link->replaceRules(['version' => 1, 'rules' => [
            ['match' => ['device' => ['desktop']], 'target' => 'https://example.com/device'],
            ['match' => ['country' => ['DE']], 'target' => 'https://example.com/country'],
            ['match' => ['language' => ['en']], 'target' => 'https://example.com/lang'],
        ]], new \DateTimeImmutable());
        self::flush();
        $this->captureLog();

        self::visit($client, '/unknown', ['HTTP_USER_AGENT' => UserAgents::CURL, 'HTTP_ACCEPT_LANGUAGE' => '*', 'REMOTE_ADDR' => self::UNMAPPED]);

        self::assertResponseHeaderSame('Location', 'https://example.com/default');
        $row = self::clicksOf($link->getId())[0];
        self::assertSame('default', $row['resolved_by']);
        self::assertSame([null, null, null, false], [$row['device_type'], $row['os'], $row['country'], $row['is_bot']]);
        self::assertSame([], $this->recordsAtNoticeOrAbove());
    }

    public function testCommonUserAgentsAreDetected(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'agents']);
        $agents = [UserAgents::IPHONE_SAFARI, UserAgents::ANDROID_PHONE_CHROME, UserAgents::IPAD_SAFARI, UserAgents::WINDOWS_CHROME, UserAgents::MACOS_SAFARI, UserAgents::LINUX_FIREFOX, UserAgents::GOOGLEBOT];
        foreach ($agents as $ua) {
            self::visit($client, '/agents', ['HTTP_USER_AGENT' => $ua]);
            self::assertResponseStatusCodeSame(302);
        }

        $rows = self::clicksOf($link->getId());
        self::assertSame([['smartphone', 'iOS'], ['smartphone', 'Android'], ['tablet', 'iOS'], ['desktop', 'Windows'], ['desktop', 'macOS'], ['desktop', 'Linux'], [null, null]], array_map(static fn (array $r): array => [$r['device_type'], $r['os']], $rows));
        self::assertSame([false, false, false, false, false, false, true], array_column($rows, 'is_bot'));
        foreach (\array_slice($rows, 0, 6) as $row) {
            self::assertNotNull($row['browser']);
        }
    }

    public function testUnrecognisedUserAgentFallsToTheVariants(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'unrec']);
        $link->replaceRules(['version' => 1, 'rules' => [['match' => ['device' => ['smartphone']], 'target' => 'https://example.com/m']], 'variants' => self::VARIANTS], new \DateTimeImmutable());
        self::flush();

        self::visit($client, '/unrec', ['HTTP_USER_AGENT' => UserAgents::UNKNOWN]);

        self::assertContains($client->getResponse()->headers->get('Location'), ['https://example.com/a', 'https://example.com/b']);
        $row = self::clicksOf($link->getId())[0];
        self::assertSame([null, null, false], [$row['device_type'], $row['os'], $row['is_bot']]);
    }

    public function testAcceptLanguageQualityOrderingAndSubtag(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'lang']);
        $link->replaceRules(['version' => 1, 'rules' => [
            ['match' => ['language' => ['ru']], 'target' => 'https://example.com/ru'],
            ['match' => ['language' => ['en']], 'target' => 'https://example.com/en'],
            ['match' => ['language' => ['fr']], 'target' => 'https://example.com/fr'],
            ['match' => ['language' => ['zh']], 'target' => 'https://example.com/zh'],
        ], 'variants' => self::VARIANTS], new \DateTimeImmutable());
        self::flush();

        $locations = [];
        foreach (['uk-UA;q=0.8, ru;q=0.9', 'en-US,en;q=0.9', 'de-CH;q=0.7, fr-CH;q=0.9', 'zh-Hant-TW', '*', 'x'] as $header) {
            self::visit($client, '/lang', ['HTTP_ACCEPT_LANGUAGE' => $header]);
            $locations[] = $client->getResponse()->headers->get('Location');
        }

        self::assertSame(['https://example.com/ru', 'https://example.com/en', 'https://example.com/fr', 'https://example.com/zh'], \array_slice($locations, 0, 4));
        self::assertContains($locations[4], ['https://example.com/a', 'https://example.com/b']);
        self::assertContains($locations[5], ['https://example.com/a', 'https://example.com/b']);
    }

    public function testSameVisitorSameVariant(): void
    {
        $client = self::createClient();
        $link = LinkFactory::createOne(['slug' => 'abtest']);
        $link->replaceRules(['version' => 1, 'variants' => self::VARIANTS], new \DateTimeImmutable());
        self::flush();

        $locations = [];
        for ($i = 0; $i < 20; ++$i) {
            self::visit($client, '/abtest');
            $locations[] = $client->getResponse()->headers->get('Location');
        }

        self::assertCount(1, array_unique($locations));
        $variants = array_unique(array_column(self::clicksOf($link->getId()), 'variant'));
        self::assertCount(1, $variants);
        self::assertSame('https://example.com/'.strtolower((string) reset($variants)), $locations[0]);
    }

    public function testUtmOnARuleTargetForGetAndHead(): void
    {
        $client = self::createClient();
        $link = LinkFactory::new()->withUtm(['utm_source' => 'news'])->create(['slug' => 'utm']);
        $link->replaceRules(['version' => 1, 'rules' => [['match' => ['device' => ['smartphone']], 'target' => 'https://apps.apple.com/app/id123?x=1']]], new \DateTimeImmutable());
        self::flush();

        self::visit($client, '/utm', ['HTTP_USER_AGENT' => UserAgents::IPHONE_SAFARI]);
        self::assertResponseHeaderSame('Location', 'https://apps.apple.com/app/id123?x=1&utm_source=news');
        self::visit($client, '/utm', ['HTTP_USER_AGENT' => UserAgents::IPHONE_SAFARI], 'HEAD');
        self::assertResponseStatusCodeSame(302);
        self::assertResponseHeaderSame('Location', 'https://apps.apple.com/app/id123?x=1&utm_source=news');

        self::assertCount(1, self::clicksOf($link->getId()), 'HEAD records nothing');
    }

    public function testResolutionIsRecordedOnTheClick(): void
    {
        $client = self::createClient();
        $device = LinkFactory::createOne(['slug' => 'rec-device']);
        $device->replaceRules(['version' => 1, 'rules' => [['match' => ['device' => ['smartphone']], 'target' => 'https://example.com/m']]], new \DateTimeImmutable());
        $variants = LinkFactory::createOne(['slug' => 'rec-variants']);
        $variants->replaceRules(['version' => 1, 'variants' => self::VARIANTS], new \DateTimeImmutable());
        $plain = LinkFactory::createOne(['slug' => 'rec-plain']);
        self::flush();

        self::visit($client, '/rec-device', ['HTTP_USER_AGENT' => UserAgents::IPHONE_SAFARI, 'REMOTE_ADDR' => self::DE, 'HTTP_ACCEPT_LANGUAGE' => 'de']);
        self::visit($client, '/rec-variants', ['HTTP_USER_AGENT' => UserAgents::LINUX_FIREFOX, 'HTTP_ACCEPT_LANGUAGE' => '', 'REMOTE_ADDR' => self::UNMAPPED]);
        self::visit($client, '/rec-plain', ['HTTP_USER_AGENT' => UserAgents::IPHONE_SAFARI, 'REMOTE_ADDR' => self::DE]);

        $first = self::clicksOf($device->getId())[0];
        self::assertSame(['smartphone', 'iOS', false, 'DE', 'device', null], [$first['device_type'], $first['os'], $first['is_bot'], $first['country'], $first['resolved_by'], $first['variant']]);
        self::assertNotNull($first['browser']);
        $second = self::clicksOf($variants->getId())[0];
        self::assertSame(['desktop', 'Linux', null, 'variant'], [$second['device_type'], $second['os'], $second['country'], $second['resolved_by']]);
        self::assertContains($second['variant'], ['A', 'B']);
        $third = self::clicksOf($plain->getId())[0];
        self::assertSame(['default', null, 'smartphone', 'iOS', 'DE', false], [$third['resolved_by'], $third['variant'], $third['device_type'], $third['os'], $third['country'], $third['is_bot']]);
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

    /** @return list<string> */
    private function recordsAtNoticeOrAbove(): array
    {
        $records = [];
        foreach ($this->log->getRecords() as $record) {
            if ($record->level->value >= Logger::NOTICE) {
                $records[] = $record->message;
            }
        }

        return $records;
    }
}
