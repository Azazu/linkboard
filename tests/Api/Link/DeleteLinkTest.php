<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Spec links: "Delete a link".
 */
#[CoversNothing]
final class DeleteLinkTest extends LinkApiTestCase
{
    public function testDeleteThenReuseTheSlug(): void
    {
        $client = self::createClient();
        $a = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $a, 'slug' => 'sale']);
        $token = $this->token($client, 'a@example.com');
        $id = (string) $link->getId();

        $this->api($client, $token, 'DELETE', "/api/v1/links/$id");
        self::assertResponseStatusCodeSame(204);

        $this->api($client, $token, 'GET', "/api/v1/links/$id");
        self::assertResponseStatusCodeSame(404);
        $this->api($client, $token, 'DELETE', "/api/v1/links/$id");
        self::assertResponseStatusCodeSame(404);

        $this->api($client, $token, 'POST', '/api/v1/links', ['targetUrl' => 'https://e.com', 'slug' => 'sale']);
        self::assertResponseStatusCodeSame(201);
    }
}
