<?php

declare(strict_types=1);

namespace App\Redirect;

use App\Click\Visit;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the Visit from a request and classifies each routing input as
 * absent, well-formed or hostile (design decision 4). Hostile: a User-Agent
 * over 1024 bytes, not valid UTF-8 or with control characters; an
 * Accept-Language over 256 bytes or outside the header grammar; a Sec-CH-UA*
 * header over 256 bytes or with control characters; a trusted proxy's country
 * header over 16 bytes or with control characters. Hostile values are dropped
 * and their issue class recorded; the user agent is still cut to 1024 bytes
 * for the visitor hash. The country header is read only when the request came
 * through a trusted proxy — an untrusted peer can never supply a country.
 */
final readonly class VisitFactory
{
    public const string ISSUE_USER_AGENT_OVERSIZED = 'user_agent_oversized';
    public const string ISSUE_USER_AGENT_MALFORMED = 'user_agent_malformed';
    public const string ISSUE_ACCEPT_LANGUAGE_OVERSIZED = 'accept_language_oversized';
    public const string ISSUE_ACCEPT_LANGUAGE_MALFORMED = 'accept_language_malformed';
    public const string ISSUE_CLIENT_HINTS_OVERSIZED = 'client_hints_oversized';
    public const string ISSUE_CLIENT_HINTS_MALFORMED = 'client_hints_malformed';
    public const string ISSUE_PROXY_COUNTRY_MALFORMED = 'proxy_country_malformed';

    /** Comma-separated language ranges with optional ;q= weights (RFC 9110 §12.5.4, simplified) */
    private const string ACCEPT_LANGUAGE_GRAMMAR = '/^\s*[A-Za-z0-9*]+(?:-[A-Za-z0-9]+)*\s*(?:;\s*q\s*=\s*(?:0(?:\.\d{1,3})?|1(?:\.0{1,3})?)\s*)?(?:,\s*[A-Za-z0-9*]+(?:-[A-Za-z0-9]+)*\s*(?:;\s*q\s*=\s*(?:0(?:\.\d{1,3})?|1(?:\.0{1,3})?)\s*)?)*$/D';
    private const string CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    public function __construct(
        #[Autowire(env: 'GEOIP_COUNTRY_HEADER')]
        private string $countryHeader,
    ) {
    }

    public function fromRequest(Request $request, \DateTimeImmutable $now, bool $isHead): Visit
    {
        $issues = [];

        $userAgent = $request->headers->get('User-Agent') ?? '';
        if (\strlen($userAgent) > Visit::USER_AGENT_MAX_BYTES) {
            $issues[self::ISSUE_USER_AGENT_OVERSIZED] = true;
        } elseif (!self::isWellFormedText($userAgent)) {
            $issues[self::ISSUE_USER_AGENT_MALFORMED] = true;
        }

        $acceptLanguage = $request->headers->get('Accept-Language');
        if (null !== $acceptLanguage && '' === trim($acceptLanguage)) {
            $acceptLanguage = null;
        }
        if (null !== $acceptLanguage) {
            if (\strlen($acceptLanguage) > Visit::ACCEPT_LANGUAGE_MAX_BYTES) {
                $issues[self::ISSUE_ACCEPT_LANGUAGE_OVERSIZED] = true;
                $acceptLanguage = null;
            } elseif (1 !== preg_match(self::ACCEPT_LANGUAGE_GRAMMAR, $acceptLanguage)) {
                $issues[self::ISSUE_ACCEPT_LANGUAGE_MALFORMED] = true;
                $acceptLanguage = null;
            }
        }

        $clientHints = [];
        foreach ($request->headers->all() as $name => $values) {
            if (!str_starts_with($name, 'sec-ch-ua')) {
                continue;
            }
            $value = $values[0] ?? null;
            if (null === $value || '' === $value) {
                continue;
            }
            if (\strlen($value) > Visit::CLIENT_HINT_MAX_BYTES) {
                $issues[self::ISSUE_CLIENT_HINTS_OVERSIZED] = true;
                continue;
            }
            if (!self::isWellFormedText($value)) {
                $issues[self::ISSUE_CLIENT_HINTS_MALFORMED] = true;
                continue;
            }
            $clientHints[$name] = $value;
        }

        $proxyCountry = null;
        if ($request->isFromTrustedProxy()) {
            $proxyCountry = $request->headers->get($this->countryHeader);
            if (null !== $proxyCountry && (\strlen($proxyCountry) > Visit::PROXY_COUNTRY_MAX_BYTES || !self::isWellFormedText($proxyCountry))) {
                $issues[self::ISSUE_PROXY_COUNTRY_MALFORMED] = true;
                $proxyCountry = null;
            }
        }

        $referer = $request->headers->get('Referer');

        return new Visit(
            $request->getClientIp() ?? '',
            substr($userAgent, 0, Visit::USER_AGENT_MAX_BYTES),
            null === $referer ? null : substr($referer, 0, Visit::REFERER_MAX_BYTES),
            $now,
            $isHead,
            $acceptLanguage,
            $clientHints,
            $proxyCountry,
            array_keys($issues),
        );
    }

    private static function isWellFormedText(string $value): bool
    {
        return 1 !== preg_match(self::CONTROL_CHARACTERS, $value) && mb_check_encoding($value, 'UTF-8');
    }
}
