<?php

declare(strict_types=1);

// Gives phpstan-symfony the console application so command names resolve.
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

return new Application(new Kernel('test', false));
