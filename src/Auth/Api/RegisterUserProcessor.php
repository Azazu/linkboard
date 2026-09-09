<?php

declare(strict_types=1);

namespace App\Auth\Api;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<RegistrationInput, UserOutput>
 */
final readonly class RegisterUserProcessor implements ProcessorInterface
{
    public function __construct(
        private UserRepositoryInterface $users,
        private EntityManagerInterface $em,
        private PasswordHasherFactoryInterface $hasherFactory,
    ) {
    }

    /**
     * @param RegistrationInput    $data
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UserOutput
    {
        $hash = $this->hasherFactory->getPasswordHasher(User::class)->hash($data->password);
        $user = new User($data->email, $hash, new \DateTimeImmutable());
        $this->users->add($user);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Lost the race against a concurrent registration: the index is the
            // authority, the answer is the same 422 the validator would have given.
            // API Platform's exception, not Symfony's ValidationFailedException,
            // which the error listener would render as a 500.
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation('An account with this email already exists.', null, [], $data, 'email', $data->email)]));
        }

        return UserOutput::fromUser($user);
    }
}
