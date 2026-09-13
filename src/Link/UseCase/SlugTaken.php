<?php

declare(strict_types=1);

namespace App\Link\UseCase;

/**
 * A slug the caller chose is already in use — the unique index said so, after
 * the pre-check passed (design decision 2 of add-web-ui). Each caller renders
 * it in its own vocabulary: the API as a 422 violation on `slug`, the UI as an
 * error on the slug field.
 */
final class SlugTaken extends \RuntimeException
{
    public function __construct(public readonly string $slug, ?\Throwable $previous = null)
    {
        parent::__construct('This slug is already taken.', 0, $previous);
    }
}
