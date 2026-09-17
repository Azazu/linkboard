<?php

declare(strict_types=1);

namespace App\Tests\Api\GraphQl;

use App\Tests\Factory\UserFactory;
use App\Tests\Support\Json;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Posting GraphQL documents over the test kernel, at the documented path.
 */
abstract class GraphQlTestCase extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    protected const string PATH = '/api/v1/graphql';
    protected const string UNVERSIONED_PATH = '/api/graphql';

    protected function token(KernelBrowser $client, string $email): string
    {
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => $email, 'password' => UserFactory::PASSWORD]);

        return Json::string(Json::decode($client->getResponse()->getContent()), 'token');
    }

    /**
     * @param array<string, mixed> $body extra body members, such as `variables` or `operationName`
     */
    protected function post(KernelBrowser $client, ?string $token, string $query, array $body = [], string $path = self::PATH): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $client->request('POST', $path, server: $server, content: json_encode(['query' => $query] + $body, \JSON_THROW_ON_ERROR));
    }

    /**
     * The `data` of a successful response, asserting it carried no errors.
     *
     * @return array<array-key, mixed>
     */
    protected function data(KernelBrowser $client): array
    {
        self::assertResponseStatusCodeSame(200);
        $body = Json::decode($client->getResponse()->getContent());
        self::assertArrayNotHasKey('errors', $body, 'the query carried errors: '.json_encode($body['errors'] ?? null, \JSON_THROW_ON_ERROR));

        return Json::map($body, 'data');
    }

    /**
     * The `errors` of a response that the executor refused — 200 with errors,
     * which is GraphQL's own shape and this API's contract for anything the
     * executor decides.
     *
     * @return list<array<array-key, mixed>>
     */
    protected function errors(KernelBrowser $client): array
    {
        self::assertResponseStatusCodeSame(200);
        $body = Json::decode($client->getResponse()->getContent());
        $errors = Json::objects($body, 'errors');
        self::assertNotSame([], $errors, 'the query was expected to be refused');

        return $errors;
    }

    protected function messages(KernelBrowser $client): string
    {
        return implode(' | ', array_map(static fn (array $e): string => \is_string($e['message'] ?? null) ? $e['message'] : '', $this->errors($client)));
    }
}
