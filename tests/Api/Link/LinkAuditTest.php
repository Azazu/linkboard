<?php

declare(strict_types=1);

namespace App\Tests\Api\Link;

use App\Link\Entity\Link;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Spec links: "Admin actions on links are audited" — positive records with an
 * exact safe context shape and no data leakage; negative paths write nothing.
 */
#[CoversNothing]
final class LinkAuditTest extends LinkApiTestCase
{
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $client = self::createClient();
        $log = self::getContainer()->getParameter('kernel.logs_dir').'/audit.log';
        \assert(\is_string($log));
        $this->log = $log;
        (new Filesystem())->dumpFile($this->log, '');
        self::ensureKernelShutdown();
        unset($client);
    }

    public function testAdminMutationsWriteOneSafeRecordEach(): void
    {
        $client = self::createClient();
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $admin = UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'audited-slug', 'targetUrl' => 'https://example.com/original']);
        $token = $this->token($client, 'admin@example.com');
        $uri = '/api/v1/links/'.$link->getId();

        $this->api($client, $token, 'PATCH', $uri, ['targetUrl' => 'https://example.org/moved']);
        self::assertResponseStatusCodeSame(200);
        $this->api($client, $token, 'PATCH', $uri, ['isActive' => false, 'maxClicks' => 5]);
        self::assertResponseStatusCodeSame(200);
        $this->api($client, $token, 'PATCH', $uri, ['isActive' => true]);
        self::assertResponseStatusCodeSame(200);
        $this->api($client, $token, 'DELETE', $uri);
        self::assertResponseStatusCodeSame(204);

        $records = $this->records();
        self::assertSame(['link.update', 'link.deactivate', 'link.activate', 'link.delete'], array_column(array_column($records, 'context'), 'action'));
        foreach ($records as $record) {
            self::assertSame(['action', 'actor_id', 'target_id', 'owner_id'], array_keys($record['context']), 'exact safe context shape');
            self::assertSame($record['message'], $record['context']['action']);
            self::assertSame((string) $admin->getId(), $record['context']['actor_id']);
            self::assertSame((string) $link->getId(), $record['context']['target_id']);
            self::assertSame((string) $owner->getId(), $record['context']['owner_id']);
            $line = json_encode($record, \JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('audited-slug', $line);
            self::assertStringNotContainsString('example.org/moved', $line);
            self::assertStringNotContainsString('example.com/original', $line);
            self::assertStringNotContainsString('@', $line);
        }
    }

    public function testOwnerActionsAndRejectedOrFailedAdminActionsWriteNothing(): void
    {
        $client = self::createClient();
        $client->disableReboot(); // the failing onFlush listener must survive until the last request
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        UserFactory::createOne(['email' => 'b@example.com']);
        UserFactory::new()->admin()->create(['email' => 'admin@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner]);
        $uri = '/api/v1/links/'.$link->getId();

        // owner on their own link: not audited
        $this->api($client, $this->token($client, 'a@example.com'), 'PATCH', $uri, ['isActive' => false, 'targetUrl' => 'https://example.com/mine']);
        self::assertResponseStatusCodeSame(200);
        // stranger: refused
        $this->api($client, $this->token($client, 'b@example.com'), 'PATCH', $uri, ['isActive' => true]);
        self::assertResponseStatusCodeSame(403);
        // admin with an invalid target: rejected by validation
        $admin = $this->token($client, 'admin@example.com');
        $this->api($client, $admin, 'PATCH', $uri, ['targetUrl' => 'http://10.0.0.1/']);
        self::assertResponseStatusCodeSame(422);
        // admin whose write fails during the flush
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getEventManager()->addEventListener([Events::onFlush], new class {
            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityUpdates() as $entity) {
                    if ($entity instanceof Link) {
                        throw new \RuntimeException('simulated storage failure');
                    }
                }
            }
        });
        $this->api($client, $admin, 'PATCH', $uri, ['isActive' => true]);
        self::assertResponseStatusCodeSame(500);

        self::assertSame([], $this->records(), 'no success record for owner, rejected or failed actions');
    }

    /**
     * @return list<array{message: string, context: array<string, string>}>
     */
    private function records(): array
    {
        $lines = array_values(array_filter(explode("\n", file_get_contents($this->log) ?: '')));

        return array_map(static function (string $line): array {
            $record = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            \assert(\is_array($record) && \is_string($record['message']) && \is_array($record['context']));

            /** @var array{message: string, context: array<string, string>} $record */
            return $record;
        }, $lines);
    }
}
