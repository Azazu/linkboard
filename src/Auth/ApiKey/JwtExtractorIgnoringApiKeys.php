<?php

declare(strict_types=1);

namespace App\Auth\ApiKey;

use Lexik\Bundle\JWTAuthenticationBundle\TokenExtractor\TokenExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decorates Lexik's chain extractor so the JWT authenticator does not claim
 * `Bearer lb_…` (design decision 1): its factory priority is higher than the
 * access-token authenticator's and a failed JWT parse would answer 401 before
 * the key handler ever ran. A JWT never starts with `lb_`.
 */
#[AsDecorator('lexik_jwt_authentication.extractor.chain_extractor')]
final readonly class JwtExtractorIgnoringApiKeys implements TokenExtractorInterface
{
    public function __construct(
        #[AutowireDecorated]
        private TokenExtractorInterface $inner,
    ) {
    }

    public function extract(Request $request): string|false
    {
        $token = $this->inner->extract($request);
        if (\is_string($token) && ApiKeyHeaderExtractor::looksLikeApiKey($token)) {
            return false;
        }

        return $token;
    }
}
