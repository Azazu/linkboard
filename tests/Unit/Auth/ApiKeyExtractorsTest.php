<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\ApiKey\ApiKeyHeaderExtractor;
use App\Auth\ApiKey\JwtExtractorIgnoringApiKeys;
use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** Design decision 1: each bearer value reaches exactly one authenticator. */
#[CoversClass(ApiKeyHeaderExtractor::class)]
#[CoversClass(JwtExtractorIgnoringApiKeys::class)]
final class ApiKeyExtractorsTest extends TestCase
{
    private const string KEY = 'lb_ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmn';
    private const string JWT = 'eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ4In0.c2ln';

    public function testHeaderExtractorAcceptsOnlyTheKeyShape(): void
    {
        $extractor = new ApiKeyHeaderExtractor();

        self::assertSame(self::KEY, $extractor->extractAccessToken(self::request('Bearer '.self::KEY)));
        self::assertNull($extractor->extractAccessToken(self::request('Bearer '.self::JWT)), 'a JWT is not a key');
        self::assertNull($extractor->extractAccessToken(self::request('Bearer '.substr(self::KEY, 0, -1))), '39 characters');
        self::assertNull($extractor->extractAccessToken(self::request('Bearer '.self::KEY.'A')), '41 characters');
        self::assertNull($extractor->extractAccessToken(self::request('bearer '.self::KEY)), 'lowercase scheme');
        self::assertNull($extractor->extractAccessToken(self::request('Bearer lb_')), 'no body');
        self::assertNull($extractor->extractAccessToken(new Request()), 'no header');
    }

    public function testJwtExtractorDeclinesApiKeysAndPassesJwtsThrough(): void
    {
        $inner = new class implements TokenExtractorInterface {
            public function extract(Request $request): string|false
            {
                $header = (string) $request->headers->get('Authorization');

                return str_starts_with($header, 'Bearer ') ? substr($header, 7) : false;
            }
        };
        $decorator = new JwtExtractorIgnoringApiKeys($inner);

        self::assertFalse($decorator->extract(self::request('Bearer '.self::KEY)), 'a key is not offered to the JWT authenticator');
        self::assertSame(self::JWT, $decorator->extract(self::request('Bearer '.self::JWT)));
        self::assertFalse($decorator->extract(new Request()));
    }

    private static function request(string $authorization): Request
    {
        return new Request(server: ['HTTP_AUTHORIZATION' => $authorization]);
    }
}
