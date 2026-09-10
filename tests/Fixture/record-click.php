<?php

declare(strict_types=1);

// Records one click for the link whose slug is argv[1] through the container's
// ClickRecorderInterface and prints A (allowed) or E (exhausted). Spawned in
// parallel by DbalClickRecorderTest to prove the conditional UPDATE keeps the
// click limit exact across processes (design decision 3).

use App\Click\ClickFacts;
use App\Click\Recorder\DbalClickRecorder;
use App\Click\RefererHost;
use App\Click\Visit;
use App\Click\VisitorHasher;
use App\Kernel;
use App\Link\LinkRepositoryInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$slug = $argv[1] ?? throw new InvalidArgumentException('slug required');
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');

$link = $container->get(LinkRepositoryInterface::class)->findBySlug($slug) ?? throw new RuntimeException('no such link');
$recorder = new DbalClickRecorder($container->get('doctrine.dbal.default_connection'), new VisitorHasher('test-only-visitor-salt'), new RefererHost('http://localhost:8082'));
$outcome = $recorder->record($link, new Visit('198.51.100.'.random_int(1, 254), 'race/1.0', null, new DateTimeImmutable()), ClickFacts::default());

echo $outcome->name[0];
