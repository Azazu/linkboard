<?php

declare(strict_types=1);

// Drives CreateApiKeyProcessor::create() in its own process so several creations
// for one owner can race against real PostgreSQL (ApiKeyCreationConcurrencyTest).
//   setup <email>    create the user with nine active keys, committed; prints the user id
//   create <email>   one creation; prints S (created) or C (409 conflict)
//   cleanup <email>  delete the user (keys cascade)
// Each process boots the test kernel without the test-suite transaction, so
// its writes are committed and visible to the others.

use App\Auth\Api\ApiKeys\CreateApiKeyInput;
use App\Auth\Api\ApiKeys\CreateApiKeyProcessor;
use App\Auth\Entity\ApiKey;
use App\Auth\Entity\User;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

[$mode, $email] = [$argv[1] ?? '', $argv[2] ?? ''];
$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(EntityManagerInterface::class);
$now = new DateTimeImmutable();

$find = static fn (): ?User => $em->getRepository(User::class)->findOneBy(['email' => $email]);

switch ($mode) {
    case 'setup':
        $user = new User($email, 'not-a-real-hash', $now);
        $em->persist($user);
        for ($i = 0; $i < 9; ++$i) {
            $plaintext = 'lb_'.bin2hex(random_bytes(20));
            $em->persist(new ApiKey($user, 'seed '.$i, hash('sha256', $plaintext), substr($plaintext, 0, 8), null, $now));
        }
        $em->flush();
        echo $user->getId()->toRfc4122(), "\n";
        break;
    case 'create':
        $user = $find() ?? throw new RuntimeException('no user');
        $input = new CreateApiKeyInput();
        $input->name = 'race '.getmypid();
        try {
            $container->get(CreateApiKeyProcessor::class)->create($user, $input);
            echo 'S';
        } catch (ConflictHttpException) {
            echo 'C';
        }
        break;
    case 'cleanup':
        if (null !== ($user = $find())) {
            $em->remove($user);
            $em->flush();
        }
        break;
    default:
        throw new InvalidArgumentException('usage: create-api-key.php setup|create|cleanup <email>');
}
