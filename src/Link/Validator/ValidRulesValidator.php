<?php

declare(strict_types=1);

namespace App\Link\Validator;

use App\Link\Api\RulesInput;
use App\Link\Rules\RulesDocumentParser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidRulesValidator extends ConstraintValidator
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RulesDocumentParser $parser,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidRules) {
            throw new UnexpectedTypeException($constraint, ValidRules::class);
        }
        $input = RulesInput::fromRequest($this->requestStack->getCurrentRequest());
        if (!$input->present || null === $input->node) {
            return; // absent or null: nothing to validate (the processors clear or keep)
        }
        if (!$input->node instanceof \stdClass) {
            $this->context->buildViolation($constraint->notAnObjectMessage)->addViolation();

            return;
        }
        foreach ($this->parser->parse($input->node)->violations as $violation) {
            $builder = $this->context->buildViolation($violation->message);
            if ('' !== $violation->path) {
                $builder->atPath($violation->path);
            }
            $builder->addViolation();
        }
    }
}
