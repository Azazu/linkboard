<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\Api\ApiKeys\CreateApiKeyProcessor;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Spec api-keys "Concurrent creations at the cap" (design decision 5): from
 * nine committed active keys, twelve processes create at once against real
 * PostgreSQL — the owner-row lock serialises them, so exactly one succeeds,
 * eleven get the conflict and ten active keys remain.
 */
#[CoversClass(CreateApiKeyProcessor::class)]
final class ApiKeyCreationConcurrencyTest extends KernelTestCase
{
    // The suite's database reset must happen before this process opens its
    // connection (the reset terminates open connections); the trait orders it.
    use ResetDatabase;

    public function testConcurrentCreationsAtTheCapYieldOneSuccessAndTenActiveKeys(): void
    {
        $email = 'race-'.bin2hex(random_bytes(6)).'@example.com';
        $script = \dirname(__DIR__, 3).'/tests/Fixture/create-api-key.php';
        $root = \dirname(__DIR__, 3);
        $userId = trim((new Process(['php', $script, 'setup', $email], $root))->mustRun()->getOutput());

        try {
            $processes = [];
            for ($i = 0; $i < 12; ++$i) {
                $process = new Process(['php', $script, 'create', $email], $root);
                $process->start();
                $processes[] = $process;
            }
            $outcomes = '';
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $outcomes .= $process->getOutput();
            }

            self::assertSame(1, substr_count($outcomes, 'S'), "exactly one creation succeeds: $outcomes");
            self::assertSame(11, substr_count($outcomes, 'C'), "the others get the conflict: $outcomes");
            self::bootKernel();
            $connection = self::getContainer()->get('doctrine.dbal.default_connection');
            self::assertInstanceOf(Connection::class, $connection);
            self::assertSame(10, (int) $connection->fetchOne('SELECT count(*) FROM api_keys WHERE user_id = :u AND revoked_at IS NULL', ['u' => $userId]));
        } finally {
            (new Process(['php', $script, 'cleanup', $email], $root))->run();
        }
    }
}
