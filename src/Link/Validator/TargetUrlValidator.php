<?php

declare(strict_types=1);

namespace App\Link\Validator;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class TargetUrlValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TargetUrl) {
            throw new UnexpectedTypeException($constraint, TargetUrl::class);
        }
        if (null === $value) {
            return; // absence is the caller's contract (optional on PATCH); '' is judged by the policy
        }
        if (!\is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }
        if (!TargetUrlPolicy::isAllowed($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
