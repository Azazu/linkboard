<?php

declare(strict_types=1);

// One `GET /health?deep=1` against the PROD kernel, in a process of its own
// (HealthDeepProdTest). A child process rather than an in-process kernel for
// two reasons: the DSNs under test are the child's environment, so no test can
// leak them into another; and the prod monolog handlers write to stderr, which
// the parent captures — that is how a test asserts the authorization warnings
// that a 404 deliberately does not reveal.
//   --authorization=<header value>   sent as Authorization; omitted = no header
// Prints one JSON line on stdout: status, contentType, cacheControl, body and
// the seconds the kernel took to answer. Log records arrive on stderr.

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$authorization = null;
foreach (array_slice($argv, 1) as $arg) {
    if (1 === preg_match('/^--authorization=(.*)$/s', $arg, $m)) {
        $authorization = $m[1];
    } else {
        fwrite(\STDERR, "unknown argument $arg\n");
        exit(64);
    }
}

/**
 * The prod kernel with a cache directory of its own. `var/cache/prod` is
 * shared with HealthTest, which removes it before each of its own prod boots:
 * a container build takes a blocking lock on a file in that directory, so a
 * removal underneath a building process is a race with no upper bound. A
 * named subclass (not an anonymous one — the container class is derived from
 * the kernel's name) keeps the configuration identical and the cache apart.
 */
final class ProbeProdKernel extends Kernel
{
    public function getCacheDir(): string
    {
        return $this->getProjectDir().'/var/cache/probe-prod';
    }
}

$kernel = new ProbeProdKernel('prod', false);
$request = Request::create('/health?deep=1', server: null === $authorization ? [] : ['HTTP_AUTHORIZATION' => $authorization]);

$started = microtime(true);
$response = $kernel->handle($request);
$elapsed = microtime(true) - $started;

fwrite(\STDOUT, json_encode([
    'status' => $response->getStatusCode(),
    'contentType' => $response->headers->get('Content-Type'),
    'cacheControl' => $response->headers->get('Cache-Control'),
    'body' => (string) $response->getContent(),
    'elapsed' => $elapsed,
], \JSON_THROW_ON_ERROR)."\n");
