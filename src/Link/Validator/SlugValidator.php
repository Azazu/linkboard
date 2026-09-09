<?php

declare(strict_types=1);

namespace App\Link\Validator;

use App\Link\LinkRepositoryInterface;
use App\Link\ReservedSlugs;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class SlugValidator extends ConstraintValidator
{
    public function __construct(private readonly LinkRepositoryInterface $links)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Slug) {
            throw new UnexpectedTypeException($constraint, Slug::class);
        }
        if (null === $value) {
            return; // a generated slug will be used
        }
        if (!\is_string($value)) {
            throw new UnexpectedTypeException($value, 'string');
        }
        if (1 !== preg_match(Slug::PATTERN, $value)) {
            $this->context->buildViolation($constraint->formatMessage)->addViolation();

            return;
        }
        if (ReservedSlugs::contains($value)) {
            $this->context->buildViolation($constraint->reservedMessage)->addViolation();

            return;
        }
        // pre-check for a proper 422; the unique index remains the authority under concurrency
        if ($this->links->slugExists($value)) {
            $this->context->buildViolation($constraint->takenMessage)->addViolation();
        }
    }
}
