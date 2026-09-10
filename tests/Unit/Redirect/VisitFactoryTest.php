<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect;

use App\Click\Visit;
use App\Redirect\VisitFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Design decision 4: every routing input is absent, well-formed or hostile;
 * hostile values are dropped and classified; the country header is read only
 * from a trusted proxy.
 */
#[CoversClass(VisitFactory::class)]
#[CoversClass(Visit::class)]
final class VisitFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    public function testCleanRequestHasNoIssues(): void
    {
        $visit = $this->visit(['HTTP_USER_AGENT' => 'Probe/1.0', 'HTTP_ACCEPT_LANGUAGE' => 'uk-UA;q=0.8, ru;q=0.9', 'HTTP_SEC_CH_UA' => '"Chromium";v="125"', 'HTTP_SEC_CH_UA_MOBILE' => '?1', 'HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_REFERER' => 'https://ref.example.org/x']);

        self::assertSame([], $visit->inputIssues);
        self::assertFalse($visit->isHostile());
        self::assertSame('203.0.113.7', $visit->clientIp);
        self::assertSame('Probe/1.0', $visit->userAgent);
        self::assertSame('uk-UA;q=0.8, ru;q=0.9', $visit->acceptLanguage, 'carried verbatim');
        self::assertSame(['sec-ch-ua' => '"Chromium";v="125"', 'sec-ch-ua-mobile' => '?1'], $visit->clientHints, 'Sec-Fetch-Dest is not a client hint');
        self::assertNull($visit->proxyCountry);
        self::assertSame('https://ref.example.org/x', $visit->referer);
    }

    public function testAbsentHeadersAreNotIssues(): void
    {
        $visit = $this->visit(['HTTP_USER_AGENT' => '', 'HTTP_ACCEPT_LANGUAGE' => '']); // Request::create() would otherwise supply 'Symfony' and 'en-us,en;q=0.5'

        self::assertSame([], $visit->inputIssues);
        self::assertSame('', $visit->userAgent);
        self::assertNull($visit->acceptLanguage);
        self::assertSame([], $visit->clientHints);
        self::assertNull($visit->referer);
    }

    public function testOversizedUserAgentIsHostileAndStillCutForHashing(): void
    {
        $visit = $this->visit(['HTTP_USER_AGENT' => str_repeat('u', 1025)]);

        self::assertSame([VisitFactory::ISSUE_USER_AGENT_OVERSIZED], $visit->inputIssues);
        self::assertSame(Visit::USER_AGENT_MAX_BYTES, \strlen($visit->userAgent));
    }

    public function testMalformedUserAgentIsHostile(): void
    {
        self::assertSame([VisitFactory::ISSUE_USER_AGENT_MALFORMED], $this->visit(['HTTP_USER_AGENT' => "Mozilla/5.0 \x00 garbage"])->inputIssues);
        self::assertSame([VisitFactory::ISSUE_USER_AGENT_MALFORMED], $this->visit(['HTTP_USER_AGENT' => "Mozilla/5.0 \xff\xfe"])->inputIssues, 'invalid UTF-8');
        self::assertSame([], $this->visit(['HTTP_USER_AGENT' => str_repeat('u', 1024)])->inputIssues, 'exactly the bound is well-formed');
    }

    public function testAcceptLanguageClassification(): void
    {
        $oversized = $this->visit(['HTTP_ACCEPT_LANGUAGE' => str_repeat('de,', 86)]);
        self::assertSame([VisitFactory::ISSUE_ACCEPT_LANGUAGE_OVERSIZED], $oversized->inputIssues);
        self::assertNull($oversized->acceptLanguage);

        foreach (['de;q=abc', 'de, ,fr', 'de;q=1.5', '<script>'] as $malformed) {
            $visit = $this->visit(['HTTP_ACCEPT_LANGUAGE' => $malformed]);
            self::assertSame([VisitFactory::ISSUE_ACCEPT_LANGUAGE_MALFORMED], $visit->inputIssues, $malformed);
            self::assertNull($visit->acceptLanguage, $malformed);
        }
        foreach (['de', '*', 'en-US,en;q=0.9', 'zh-Hant-TW', 'de-CH;q=0.7, fr-CH;q=0.9', 'x', 'fr ; q=0.500'] as $wellFormed) {
            $visit = $this->visit(['HTTP_ACCEPT_LANGUAGE' => $wellFormed]);
            self::assertSame([], $visit->inputIssues, $wellFormed);
            self::assertSame($wellFormed, $visit->acceptLanguage, $wellFormed);
        }
        self::assertNull($this->visit(['HTTP_ACCEPT_LANGUAGE' => '  '])->acceptLanguage, 'blank counts as absent');
        self::assertSame([], $this->visit(['HTTP_ACCEPT_LANGUAGE' => " \t "])->inputIssues, 'spaces and tabs are the only blank');

        $oversizedBlank = $this->visit(['HTTP_ACCEPT_LANGUAGE' => str_repeat(' ', 257)]);
        self::assertSame([VisitFactory::ISSUE_ACCEPT_LANGUAGE_OVERSIZED], $oversizedBlank->inputIssues, 'the size bound is judged before the blank exception');
        self::assertNull($oversizedBlank->acceptLanguage);
        foreach (["\0", "\0\0", " \0 ", "\x7f"] as $controlOnly) {
            self::assertSame([VisitFactory::ISSUE_ACCEPT_LANGUAGE_MALFORMED], $this->visit(['HTTP_ACCEPT_LANGUAGE' => $controlOnly])->inputIssues, 'control bytes do not vanish as blank: '.bin2hex($controlOnly));
        }
    }

    public function testClientHintsClassification(): void
    {
        $visit = $this->visit(['HTTP_SEC_CH_UA' => str_repeat('a', 257), 'HTTP_SEC_CH_UA_PLATFORM' => '"Android"', 'HTTP_SEC_CH_UA_MODEL' => "Pixel\x00"]);

        self::assertSame([VisitFactory::ISSUE_CLIENT_HINTS_OVERSIZED, VisitFactory::ISSUE_CLIENT_HINTS_MALFORMED], $visit->inputIssues);
        self::assertSame(['sec-ch-ua-platform' => '"Android"'], $visit->clientHints, 'the hostile hints are dropped, the well-formed one stays');
    }

    public function testCountryHeaderOnlyFromATrustedProxy(): void
    {
        $untrusted = $this->visit(['HTTP_CF_IPCOUNTRY' => 'DE']);
        self::assertNull($untrusted->proxyCountry);
        self::assertSame([], $untrusted->inputIssues, 'an untrusted header is ignored, not hostile');

        $trusted = $this->visit(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_CF_IPCOUNTRY' => 'DE']);
        self::assertSame('DE', $trusted->proxyCountry);
        self::assertSame('203.0.113.7', $trusted->clientIp, 'the forwarded client IP is used');

        $malformed = $this->visit(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_CF_IPCOUNTRY' => str_repeat('D', 17)]);
        self::assertNull($malformed->proxyCountry);
        self::assertSame([VisitFactory::ISSUE_PROXY_COUNTRY_MALFORMED], $malformed->inputIssues);
    }

    public function testRefererIsCutAndHeadIsCarried(): void
    {
        $visit = (new VisitFactory('CF-IPCountry'))->fromRequest(Request::create('/s', 'HEAD', server: ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_REFERER' => 'https://example.org/'.str_repeat('r', 5000)]), new \DateTimeImmutable(), true);

        self::assertTrue($visit->isHead);
        self::assertSame(Visit::REFERER_MAX_BYTES, \strlen((string) $visit->referer));
    }

    /**
     * @param array<string, string> $server
     */
    private function visit(array $server): Visit
    {
        $request = Request::create('/s', 'GET', server: $server + ['REMOTE_ADDR' => '203.0.113.7']);

        return (new VisitFactory('CF-IPCountry'))->fromRequest($request, new \DateTimeImmutable(), false);
    }
}
