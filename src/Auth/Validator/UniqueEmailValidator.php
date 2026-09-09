<?php

declare(strict_types=1);

namespace App\Auth\Validator;

use App\Auth\UserRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueEmailValidator extends ConstraintValidator
{
    public function __construct(private readonly UserRepositoryInterface $users)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueEmail) {
            throw new UnexpectedTypeException($constraint, UniqueEmail::class);
        }
        if (null === $value || '' === $value) {
            return; // NotBlank / Email report these
        }
        if (!\is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }

        if (null !== $this->users->findByEmail($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
