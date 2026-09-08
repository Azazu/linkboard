<?php

declare(strict_types=1);

// Boots the kernel so phpstan-doctrine can read the real entity metadata.
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('test', false);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
