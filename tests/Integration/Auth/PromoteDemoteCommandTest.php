<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\Command\DemoteUserCommand;
use App\Auth\Command\PromoteUserCommand;
use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(PromoteUserCommand::class)]
#[CoversClass(DemoteUserCommand::class)]
final class PromoteDemoteCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testPromoteThenDemote(): void
    {
        UserFactory::createOne(['email' => 'ann@example.com']);
        $application = new Application(self::bootKernel());
        $repository = self::getContainer()->get(UserRepositoryInterface::class);

        $promote = new CommandTester($application->find('app:user:promote'));
        self::assertSame(0, $promote->execute(['email' => 'ann@example.com']));
        $user = $repository->findByEmail('ann@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertContains(User::ROLE_ADMIN, $user->getRoles());

        $demote = new CommandTester($application->find('app:user:demote'));
        self::assertSame(0, $demote->execute(['email' => 'ann@example.com']));
        $user = $repository->findByEmail('ann@example.com');
        self::assertInstanceOf(User::class, $user);
        self::assertNotContains(User::ROLE_ADMIN, $user->getRoles());
    }

    public function testUnknownEmailFails(): void
    {
        $tester = $this->tester('app:user:promote');

        self::assertSame(1, $tester->execute(['email' => 'nobody@example.com']));
        self::assertStringContainsString('No account', $tester->getDisplay());
    }

    private function tester(string $name): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find($name));
    }
}
