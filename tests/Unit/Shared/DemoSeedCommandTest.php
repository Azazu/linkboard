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

/** Spec demo-data "Refuses in prod": the guard runs before anything is touched. */
#[CoversClass(DemoSeedCommand::class)]
final class DemoSeedCommandTest extends TestCase
{
    public function testRefusesInProdBeforeTouchingAnything(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $users = $this->createMock(UserRepositoryInterface::class);
        $users->expects(self::never())->method('findByEmail');
        $command = new DemoSeedCommand(
            $connection,
            $this->createStub(EntityManagerInterface::class),
            $users,
            $this->createStub(PasswordHasherFactoryInterface::class),
            new RulesDocumentParser(),
            $this->createStub(ClickCounterInterface::class),
            new ReportCache($this->createStub(TagAwareCacheInterface::class), new NullLogger()),
            new NullLogger(),
            'prod',
        );
        $output = new BufferedOutput();

        $exit = $command(new SymfonyStyle(new ArrayInput([]), $output));

        self::assertSame(1, $exit);
        self::assertStringContainsString('never runs in the prod environment', $output->fetch());
    }
}
