<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

/**
 * An administrator asked to block their own account. The rule lives with the
 * action rather than with a caller (design decision 5 of
 * add-web-admin-and-stats): the API renders it as a violation on `id`, the
 * administrative page as a message on its confirmation, and neither can be
 * bypassed by reaching the other entry point.
 */
final class CannotBlockOwnAccount extends \DomainException
{
    public function __construct()
    {
        parent::__construct('An admin cannot block their own account.');
    }
}
