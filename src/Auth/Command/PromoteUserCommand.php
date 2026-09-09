<?php

declare(strict_types=1);

namespace App\Auth\Command;

use App\Auth\UserRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only way to grant ROLE_ADMIN (specification FR-AUTH-5, D5).
 */
#[AsCommand(name: 'app:user:promote', description: 'Grant ROLE_ADMIN to the account with this email')]
final class PromoteUserCommand
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument(description: 'Email of the account')] string $email): int
    {
        $user = $this->users->findByEmail($email);
        if (null === $user) {
            $io->error(\sprintf('No account for "%s".', $email));

            return Command::FAILURE;
        }

        $user->promoteToAdmin(new \DateTimeImmutable());
        $this->em->flush();
        $io->success(\sprintf('%s now has ROLE_ADMIN.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
