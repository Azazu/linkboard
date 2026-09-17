<?php

declare(strict_types=1);

namespace App\Shared\Api;

use ApiPlatform\GraphQl\Error\ErrorHandlerInterface;
use ApiPlatform\Validator\Exception\ConstraintViolationListAwareExceptionInterface;
use GraphQL\Error\ClientAware;
use GraphQL\Error\Error;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The GraphQL half of the one error format (spec graphql-api, "An internal
 * failure leaks nothing"): outside `dev`, an error the application did not
 * mean for the client is answered with a fixed message, and the real one is
 * logged instead.
 *
 * This exists because API Platform ships `RuntimeExceptionNormalizer`, which
 * copies the message of ANY `\RuntimeException` into `errors[].message`
 * whatever the debug flag says — and a Doctrine failure inside a resolver
 * arrives as exactly that, carrying the SQL it failed on. graphql-php's own
 * rule is the opposite and is the one kept here: an error is the client's
 * business only when the application said so.
 *
 * What still reaches the client, because it is the client's own request being
 * described rather than this service's internals:
 *
 * - an error with no previous exception — a document graphql-php itself
 *   refused: unparseable, unknown field, too deep, too complex;
 * - an `HttpExceptionInterface` — a status this API chose (a provider's 404,
 *   a voter's 403);
 * - a validation failure — the refused parameter and the reason, the shape
 *   REST answers too;
 * - an exception that declares itself client-safe.
 *
 * Everything else — including every unexpected `\RuntimeException` — becomes
 * the message below. `locations` and `path` stay: they point into the
 * document the caller sent.
 */
#[AsDecorator('api_platform.graphql.error_handler')]
final readonly class GraphQlErrorBoundary implements ErrorHandlerInterface
{
    /** graphql-php's own wording for an unsafe error, so both paths read alike. */
    public const string GENERIC_MESSAGE = 'Internal server error';

    public function __construct(
        #[AutowireDecorated]
        private ErrorHandlerInterface $inner,
        private LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private bool $debug = false,
    ) {
    }

    /**
     * @param Error[]                              $errors
     * @param callable(Error):array<string, mixed> $formatter
     *
     * @return array<array-key, array<string, mixed>>
     */
    public function __invoke(array $errors, callable $formatter): array
    {
        /** @var array<array-key, array<string, mixed>> $formatted */
        $formatted = ($this->inner)($errors, $formatter);

        if ($this->debug) {
            return $formatted;
        }

        foreach ($errors as $key => $error) {
            if (!\array_key_exists($key, $formatted) || self::isTheClientsBusiness($error)) {
                continue;
            }

            $this->logger->error('GraphQL execution failed', [
                'exception' => $error->getPrevious() ?? $error,
                'path' => $error->getPath(),
            ]);

            $entry = $formatted[$key];
            $entry['message'] = self::GENERIC_MESSAGE;
            unset($entry['trace']);

            $extensions = $entry['extensions'] ?? null;
            if (\is_array($extensions)) {
                unset($extensions['debugMessage']);
                if ([] === $extensions) {
                    unset($entry['extensions']);
                } else {
                    $entry['extensions'] = $extensions;
                }
            }

            $formatted[$key] = $entry;
        }

        return $formatted;
    }

    private static function isTheClientsBusiness(Error $error): bool
    {
        $previous = $error->getPrevious();

        return match (true) {
            null === $previous => true,
            $previous instanceof HttpExceptionInterface => true,
            $previous instanceof ConstraintViolationListAwareExceptionInterface => true,
            $previous instanceof ClientAware => $previous->isClientSafe(),
            default => false,
        };
    }
}
