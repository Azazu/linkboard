<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * The database phase of the deep-probe authorization: answers whether the
 * presented key hash belongs to an unrevoked, unexpired key of an unblocked
 * admin, within the given allowance — or that it could not tell.
 */
interface KeyLookupInterface
{
    public function lookup(#[\SensitiveParameter] string $hash, float $allowanceSeconds): LookupOutcome;
}
