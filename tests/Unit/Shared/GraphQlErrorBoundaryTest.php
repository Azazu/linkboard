<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use ApiPlatform\GraphQl\Error\ErrorHandler;
use ApiPlatform\GraphQl\Serializer\Exception\ErrorNormalizer;
use ApiPlatform\GraphQl\Serializer\Exception\HttpExceptionNormalizer;
use ApiPlatform\GraphQl\Serializer\Exception\RuntimeExceptionNormalizer;
use ApiPlatform\GraphQl\Serializer\Exception\ValidationExceptionNormalizer;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Api\GraphQlErrorBoundary;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Spec graphql-api "An internal failure leaks nothing".
 *
 * Every case runs the production formatter — API Platform's own normalizer
 * for the exception at hand — so what is asserted is the message the client
 * would actually have read.
 */
#[CoversClass(GraphQlErrorBoundary::class)]
final class GraphQlErrorBoundaryTest extends TestCase
{
    private const string SQL = 'Injected failure on: SELECT t0.id FROM links t0';

    public function testTheFailingInputIsApiPlatformsOwnRuntimeNormalizer(): void
    {
        // the guard removed: this is what reaches the client, with the debug
        // flag off, straight out of RuntimeExceptionNormalizer. If this ever
        // stops leaking, the boundary below has become unnecessary rather
        // than merely untested.
        $formatted = self::format(self::databaseFailure());

        self::assertSame(self::SQL, $formatted['message']);
    }

    public function testAnUnexpectedFailureIsAnsweredWithOneFixedMessage(): void
    {
        $formatted = self::handle(self::databaseFailure(), debug: false);

        self::assertSame(GraphQlErrorBoundary::GENERIC_MESSAGE, $formatted['message']);
        self::assertStringNotContainsString('SELECT', json_encode($formatted, \JSON_THROW_ON_ERROR));
    }

    public function testDevelopmentKeepsTheDetail(): void
    {
        $formatted = self::handle(self::databaseFailure(), debug: true);

        self::assertSame(self::SQL, $formatted['message'], 'the scenario bounds itself to outside dev');
    }

    public function testTheCallerStillLearnsWhereInItsDocumentTheFailureWas(): void
    {
        $formatted = self::handle(self::databaseFailure(), debug: false);

        self::assertSame(['linkSummaryReport'], $formatted['path'] ?? null);
    }

    /**
     * @return iterable<string, array{Error, string}>
     */
    public static function theClientsBusiness(): iterable
    {
        yield 'a document graphql-php itself refused' => [
            new Error('Max query depth should be 10 but got 24.'),
            'Max query depth should be 10 but got 24.',
        ];
        yield 'a status this API chose' => [
            new Error('n', null, null, [], null, new NotFoundHttpException('Link not found.')),
            'Link not found.',
        ];
        yield 'a refused parameter' => [
            new Error('n', null, null, [], null, new ValidationException(new ConstraintViolationList([
                new ConstraintViolation('granularity must be hour or day.', null, [], null, 'granularity', 'week'),
            ]))),
            'granularity must be hour or day.',
        ];
    }

    #[DataProvider('theClientsBusiness')]
    public function testWhatTheClientAskedForIsStillAnswered(Error $error, string $expected): void
    {
        $message = self::handle($error, debug: false)['message'];

        self::assertIsString($message);
        self::assertStringContainsString($expected, $message);
    }

    private static function databaseFailure(): Error
    {
        return new Error('n', null, null, [], ['linkSummaryReport'], new \RuntimeException(self::SQL));
    }

    /**
     * The formatter API Platform installs: the normalizer that claims this
     * exception, falling back to the generic one.
     *
     * @return array<string, mixed>
     */
    private static function format(Error $error): array
    {
        // the order api_platform/symfony's graphql.php registers them in:
        // validation and http before the runtime catch-all, the generic
        // normalizer last
        $normalizers = [new ValidationExceptionNormalizer(), new HttpExceptionNormalizer(), new RuntimeExceptionNormalizer(), new ErrorNormalizer()];

        foreach ($normalizers as $normalizer) {
            if ($normalizer->supportsNormalization($error)) {
                /** @var array<string, mixed> */
                return $normalizer->normalize($error);
            }
        }

        /** @var array<string, mixed> */
        return FormattedError::createFromException($error);
    }

    /** @return array<string, mixed> */
    private static function handle(Error $error, bool $debug): array
    {
        $handled = (new GraphQlErrorBoundary(new ErrorHandler(), new NullLogger(), $debug))([$error], self::format(...));

        return $handled[0];
    }
}
