<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * `GET /health?deep=1` against the prod kernel, run by tests/Fixture/
 * prod-health-request.php in a process of its own: the DSNs under test are
 * that process's environment, and the prod log handlers write to its stderr,
 * which is how a test reads the warnings the 404 deliberately withholds.
 */
final readonly class ProdHealthRequest
{
    /**
     * @param list<array{level: string, channel: string, message: string, context: array<string, mixed>}> $records
     */
    private function __construct(
        public int $status,
        public ?string $contentType,
        public ?string $cacheControl,
        public string $body,
        public float $elapsed,
        public array $records,
    ) {
    }

    /**
     * @param array<string, string> $environment DSNs and other variables for the prod kernel
     */
    public static function send(?string $authorization = null, array $environment = []): self
    {
        self::clearProdCacheOnce();
        $root = \dirname(__DIR__, 2);
        $arguments = ['php', $root.'/tests/Fixture/prod-health-request.php'];
        if (null !== $authorization) {
            $arguments[] = '--authorization='.$authorization;
        }
        $process = new Process($arguments, $root, [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_SECRET' => (string) ($_SERVER['APP_SECRET'] ?? 'prod-kernel-test-secret'),
            // the prod container has no `fixed` country resolver (a test-only service)
            'COUNTRY_RESOLVERS' => 'header,geolite2',
            'DATABASE_URL' => ProbeTestEnvironment::appDatabaseUrl(),
            'REDIS_URL' => ProbeTestEnvironment::redisUrl(),
            ...$environment,
        ], null, 20);
        try {
            $process->mustRun();
        } catch (ProcessTimedOutException $e) {
            // A bare "exceeded the timeout" tells a future reader nothing; the
            // child's own output says how far the request got.
            throw new \RuntimeException(\sprintf("the prod kernel did not answer within %.0f s.\nstdout: %s\nstderr: %s", $e->getProcess()->getTimeout() ?? 0.0, trim($process->getOutput()), trim($process->getErrorOutput())), 0, $e);
        }

        $payload = json_decode(trim($process->getOutput()), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($payload));

        return new self(
            (int) $payload['status'],
            \is_string($payload['contentType']) ? $payload['contentType'] : null,
            \is_string($payload['cacheControl']) ? $payload['cacheControl'] : null,
            (string) $payload['body'],
            (float) $payload['elapsed'],
            self::parseRecords($process->getErrorOutput()),
        );
    }

    /** @return list<array{level: string, channel: string, message: string, context: array<string, mixed>}> */
    public function withMessage(string $message): array
    {
        return array_values(array_filter($this->records, static fn (array $record): bool => $record['message'] === $message));
    }

    public function assertNothingContains(string $needle): void
    {
        \PHPUnit\Framework\Assert::assertStringNotContainsString($needle, $this->body);
        foreach ($this->records as $record) {
            \PHPUnit\Framework\Assert::assertStringNotContainsString($needle, json_encode($record, \JSON_THROW_ON_ERROR));
        }
    }

    /**
     * The prod handlers format records as one JSON object per line on stderr;
     * anything else there (a PHP warning, a deprecation) is ignored.
     *
     * @return list<array{level: string, channel: string, message: string, context: array<string, mixed>}>
     */
    private static function parseRecords(string $stderr): array
    {
        $records = [];
        foreach (explode("\n", $stderr) as $line) {
            $decoded = json_decode(trim($line), true);
            if (\is_array($decoded) && \is_string($decoded['message'] ?? null)) {
                $records[] = [
                    'level' => strtolower((string) ($decoded['level_name'] ?? '')),
                    'channel' => (string) ($decoded['channel'] ?? ''),
                    'message' => $decoded['message'],
                    'context' => \is_array($decoded['context'] ?? null) ? $decoded['context'] : [],
                ];
            }
        }

        return $records;
    }

    /**
     * The prod container is cached without resource tracking, so a stale build
     * from an earlier configuration would test the wrong thing — removed once
     * per test process, then reused by every child. The directory is the
     * children's own (tests/Fixture/prod-health-request.php): `var/cache/prod`
     * belongs to HealthTest, which removes it under its own feet, and a
     * container build holds a blocking lock on a file inside it.
     */
    private static function clearProdCacheOnce(): void
    {
        static $cleared = false;
        if (!$cleared) {
            (new Filesystem())->remove(\dirname(__DIR__, 2).'/var/cache/probe-prod');
            $cleared = true;
        }
    }
}
