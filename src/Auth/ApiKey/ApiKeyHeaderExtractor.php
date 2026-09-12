<?php

declare(strict_types=1);

namespace App\Auth\ApiKey;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\AccessToken\AccessTokenExtractorInterface;

/**
 * Extracts an API key from `Authorization: Bearer lb_…` — only the exact key
 * shape (design decision 1). Anything else in that header is not a key and is
 * left to the JWT authenticator.
 */
final class ApiKeyHeaderExtractor implements AccessTokenExtractorInterface
{
    private const string HEADER = '/^Bearer (lb_[A-Za-z0-9]{40})$/';

    public function extractAccessToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (null === $header || 1 !== preg_match(self::HEADER, $header, $m)) {
            return null;
        }

        return $m[1];
    }

    /** Whether a bearer value is an API key (the `lb_` family), for the JWT extractor's benefit. */
    public static function looksLikeApiKey(string $token): bool
    {
        return str_starts_with($token, ApiKeyGenerator::PREFIX);
    }
}
