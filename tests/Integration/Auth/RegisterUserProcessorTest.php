<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use ApiPlatform\Metadata\Post;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Auth\Api\RegisterUserProcessor;
use App\Auth\Api\RegistrationInput;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The flush-conflict branch: two registrations pass the uniqueness pre-check,
 * the loser hits the unique index. The processor is called directly, past
 * validation, exactly as that race would reach it.
 */
#[CoversClass(RegisterUserProcessor::class)]
final class RegisterUserProcessorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testLosingTheUniqueIndexRaceIsAnApiPlatformValidationExceptionOnEmail(): void
    {
        UserFactory::createOne(['email' => 'Ann@Example.com']);
        $processor = self::getContainer()->get(RegisterUserProcessor::class);
        $input = new RegistrationInput();
        $input->email = 'ann@example.com';
        $input->password = 'correct-horse-battery';

        try {
            $processor->process($input, new Post());
            self::fail('the unique index must reject the duplicate');
        } catch (ValidationException $e) {
            $violations = $e->getConstraintViolationList();
            self::assertCount(1, $violations);
            self::assertSame('email', $violations->get(0)->getPropertyPath());
        }
    }
}
