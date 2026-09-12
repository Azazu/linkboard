<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Health\KeyLookupInterface;
use App\Shared\Health\LookupOutcome;

/**
 * The real lookup, paused right after its answer: `$between` runs after the
 * database answered and before the authorizer continues to its memory write —
 * the window in which a revocation and another request's denial can land. The
 * ordering under test is thus established by the mechanism (the request's own
 * token read precedes its statement), not by timestamps the test supplies.
 */
final class InterleavingLookup implements KeyLookupInterface
{
    public ?LookupOutcome $outcome = null;

    /**
     * @param callable(): void $between
     */
    public function __construct(
        private readonly KeyLookupInterface $inner,
        private $between,
    ) {
    }

    public function lookup(string $hash, float $allowanceSeconds): LookupOutcome
    {
        $this->outcome = $this->inner->lookup($hash, $allowanceSeconds);
        ($this->between)();

        return $this->outcome;
    }
}
