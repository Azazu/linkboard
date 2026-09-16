<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Spec api-error-format, "The error types are published and kept in step".
 *
 * The risk with a hand-written catalogue is not that it is wrong the day it is
 * written but that it is incomplete a change later, so the types the
 * application can produce are enumerated from the code and the document and
 * checked against it (change polish-api-and-openapi, design decision 5).
 */
#[CoversNothing]
final class ErrorCatalogueTest extends WebTestCase
{
    private const string CATALOGUE = __DIR__.'/../../docs/reference/api-errors.md';

    public function testEveryTypeTheApplicationCanProduceIsCatalogued(): void
    {
        $catalogue = file_get_contents(self::CATALOGUE);
        self::assertIsString($catalogue);

        $missing = [];
        foreach ($this->producible() as $type => $where) {
            if (!str_contains($catalogue, '`'.$type.'`')) {
                $missing[] = $type.' ('.$where.')';
            }
        }

        self::assertSame([], $missing, 'the catalogue names every error type the application can produce');
    }

    public function testTheCatalogueNamesTheRecoveryWhereThereIsOne(): void
    {
        $catalogue = file_get_contents(self::CATALOGUE);
        self::assertIsString($catalogue);

        self::assertStringContainsString('Retry-After', $catalogue, 'the rate-limit refusal names the header that carries the delay');
        self::assertStringContainsString('violations', $catalogue, 'the validation failure names the array that locates the values');
        self::assertStringContainsString('DELETE /api/v1/api-keys/{id}', $catalogue, 'the key cap names the way out of it');
    }

    /**
     * @return array<string, string> type => where it comes from
     */
    private function producible(): array
    {
        $types = [];
        foreach ($this->statusesTheDocumentDeclares() as $status) {
            $types['/errors/'.$status] = 'declared by an operation';
        }
        foreach (self::statusesProblemDetailsIsCalledWith() as $status) {
            $types['/errors/'.$status] ??= 'sent by ProblemDetails';
        }
        // the framework answers these without an operation declaring them:
        // an unknown route, a wrong method, and an unexpected failure
        foreach ([404, 405, 500] as $status) {
            $types['/errors/'.$status] ??= 'answered outside an operation';
        }
        ksort($types);

        return $types;
    }

    /**
     * @return list<int>
     */
    private function statusesTheDocumentDeclares(): array
    {
        $client = self::createClient();
        $client->request('GET', '/api/docs.json');
        self::assertResponseIsSuccessful();
        $statuses = [];
        $paths = Json::mapAt(Json::decode($client->getResponse()->getContent()), 'paths');
        foreach (array_keys($paths) as $path) {
            $item = Json::map($paths, $path);
            foreach (array_keys($item) as $method) {
                if (!\in_array($method, ['get', 'post', 'patch', 'put', 'delete'], true)) {
                    continue;
                }
                foreach (array_keys(Json::mapAt($item, $method, 'responses')) as $status) {
                    if ((int) $status >= 400) {
                        $statuses[] = (int) $status;
                    }
                }
            }
        }
        $statuses = array_values(array_unique($statuses));
        sort($statuses);

        return $statuses;
    }

    /**
     * @return list<int>
     */
    private static function statusesProblemDetailsIsCalledWith(): array
    {
        $statuses = [];
        $source = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../src'));
        foreach ($source as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (!\is_string($code) || !preg_match_all('/ProblemDetails::response\(\s*(\d{3})/', $code, $matches)) {
                continue;
            }
            foreach ($matches[1] as $status) {
                $statuses[] = (int) $status;
            }
        }
        $statuses = array_values(array_unique($statuses));
        sort($statuses);
        self::assertNotSame([], $statuses, 'the enumeration found no call at all, which means it stopped working');

        return $statuses;
    }
}
