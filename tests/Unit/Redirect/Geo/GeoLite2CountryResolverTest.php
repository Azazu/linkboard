<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redirect\Geo;

use App\Click\Visit;
use App\Redirect\Geo\GeoLite2CountryResolver;
use GeoIp2\Exception\AddressNotFoundException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Design decision 8, exception boundary: opening the database is configuration
 * (missing file → disabled with one warning); an address not found is unknown;
 * any other lookup error propagates to the redirect guard.
 */
#[CoversClass(GeoLite2CountryResolver::class)]
final class GeoLite2CountryResolverTest extends TestCase
{
    private TestHandler $log;

    protected function setUp(): void
    {
        $this->log = new TestHandler();
    }

    public function testMissingDatabaseDisablesTheResolverWithOneWarning(): void
    {
        $opened = 0;
        $resolver = $this->resolver(static function () use (&$opened): \Closure {
            ++$opened;
            throw new \InvalidArgumentException('The file "/nowhere/GeoLite2-Country.mmdb" does not exist or is not readable.');
        });

        self::assertNull($resolver->resolve($this->visit()));
        self::assertNull($resolver->resolve($this->visit()));

        self::assertSame(1, $opened, 'one attempt per process');
        self::assertCount(1, $this->log->getRecords());
        $record = $this->log->getRecords()[0];
        self::assertSame(Logger::WARNING, $record->level->value);
        self::assertSame(\InvalidArgumentException::class, $record->context['exception']);
        self::assertStringNotContainsString('203.0.113.7', json_encode($record->toArray(), \JSON_THROW_ON_ERROR));
    }

    public function testKnownAddressIsUpperCased(): void
    {
        $resolver = $this->resolver(static fn (): \Closure => static fn (string $ip): string => 'de');

        self::assertSame('DE', $resolver->resolve($this->visit()));
        self::assertCount(0, $this->log->getRecords());
    }

    public function testAddressNotFoundIsUnknownWithoutALogRecord(): void
    {
        $resolver = $this->resolver(static fn (): \Closure => static function (string $ip): never {
            throw new AddressNotFoundException('The address '.$ip.' is not in the database.');
        });

        self::assertNull($resolver->resolve($this->visit()));
        self::assertNull($resolver->resolve($this->visit('10.0.0.5')));
        self::assertCount(0, $this->log->getRecords());
    }

    public function testInvalidClientIpIsUnknownWithoutALookup(): void
    {
        $looked = 0;
        $resolver = $this->resolver(static fn (): \Closure => static function (string $ip) use (&$looked): string {
            ++$looked;

            return 'DE';
        });

        self::assertNull($resolver->resolve($this->visit('')));
        self::assertNull($resolver->resolve($this->visit('unknown')));
        self::assertSame(0, $looked);
    }

    public function testAnyOtherLookupErrorPropagates(): void
    {
        $resolver = $this->resolver(static fn (): \Closure => static function (string $ip): never {
            throw new \RuntimeException('corrupt database');
        });

        try {
            $resolver->resolve($this->visit());
            self::fail('the reader error must propagate to the redirect guard');
        } catch (\RuntimeException $e) {
            self::assertSame('corrupt database', $e->getMessage());
        }
        self::assertCount(0, $this->log->getRecords(), 'the resolver writes no record of its own; the guard does');
    }

    /**
     * @param \Closure(string): (\Closure(string): ?string) $factory
     */
    private function resolver(\Closure $factory): GeoLite2CountryResolver
    {
        return new GeoLite2CountryResolver('/nowhere/GeoLite2-Country.mmdb', new Logger('test', [$this->log]), $factory);
    }

    private function visit(string $ip = '203.0.113.7'): Visit
    {
        return new Visit($ip, 'Probe/1.0', null, new \DateTimeImmutable());
    }
}
