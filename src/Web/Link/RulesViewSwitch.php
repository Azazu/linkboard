<?php

declare(strict_types=1);

namespace App\Web\Link;

/**
 * What a switch between the two views of the rules amounts to: whether one was
 * asked for at all, and the message to show if it could not be made. The form
 * is rebuilt from the carried-over data afterwards — a form that has already
 * handled a request renders what was submitted, not what the model says now.
 */
final readonly class RulesViewSwitch
{
    private function __construct(
        public bool $happened,
        public ?string $message = null,
    ) {
    }

    public static function none(): self
    {
        return new self(false);
    }

    public static function made(): self
    {
        return new self(true);
    }

    public static function refused(string $message): self
    {
        return new self(true, $message);
    }
}
