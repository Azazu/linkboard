<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Analytics\Cache\ReportCache;
use App\Auth\UserRepositoryInterface;
use App\Click\Counter\ClickCounterInterface;
use App\Link\Rules\RulesDocumentParser;
use App\Shared\Demo\DemoSeedCommand;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Spec demo-data "Guards and re-runs": the guards run before anything is
 * touched — the `prod` refusal and the fact that no option of the command lifts
 * it. The non-overlap guarantee is a PostgreSQL advisory lock and therefore
 * lives in the integration test, where there is a database to hold it.
 */
#[CoversClass(DemoSeedCommand::class)]
final class DemoSeedCommandTest extends TestCase
{
    public function testRefusesInProdBeforeTouchingAnything(): void
    {
        $output = new BufferedOutput();

        $exit = $this->command(demoInstance: false)(new SymfonyStyle(new ArrayInput([]), $output));

        self::assertSame(1, $exit);
        self::assertStringContainsString('does not run in the prod environment', self::unwrapped($output));
    }

    public function testNoOptionOfTheCommandLiftsTheGuard(): void
    {
        // The guard is a property of the instance, not an option, because a
        // `--force` flag would travel in somebody's shell history onto a real
        // host. Every option the command accepts is passed here, `--reset`
        // included, and the mocks assert that nothing was read or written.
        $output = new BufferedOutput();

        $exit = $this->command(demoInstance: false)(
            new SymfonyStyle(new ArrayInput([]), $output),
            clicks: 500,
            days: 5,
            reset: true,
        );

        self::assertSame(1, $exit);
        // SymfonyStyle wraps the block, so the sentence is compared unwrapped
        self::assertStringContainsString('No option of this command lifts that.', self::unwrapped($output));
    }

    /**
     * A command whose collaborators refuse to be used: `transactional` and
     * `findByEmail` are never expected, so any case that reaches the database
     * fails rather than passing quietly.
     */
    /** The console block, with its wrapping and padding collapsed to single spaces. */
    private static function unwrapped(BufferedOutput $output): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $output->fetch()));
    }

    private function command(bool $demoInstance, string $environment = 'prod'): DemoSeedCommand
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('findByEmail');

        return new DemoSeedCommand(
            $connection,
            $this->createStub(EntityManagerInterface::class),
            $users,
            $this->createStub(PasswordHasherFactoryInterface::class),
            new RulesDocumentParser(),
            $this->createStub(ClickCounterInterface::class),
            new ReportCache($this->createStub(TagAwareCacheInterface::class), new NullLogger()),
            new NullLogger(),
            $environment,
            $demoInstance,
            '',
        );
    }
}
