<?php

declare(strict_types=1);

namespace App\Link;

interface SlugGeneratorInterface
{
    /** A fresh candidate; uniqueness is the caller's concern. */
    public function generate(): string;
}
