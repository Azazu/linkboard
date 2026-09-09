<?php

declare(strict_types=1);

namespace App\Tests\Integration\Link;

use App\Link\Api\CreateLinkProcessor;
use App\Link\Entity\Link;
use App\Link\LinkRepositoryInterface;
use App\Link\SlugGenerator;
use App\Link\SlugGeneratorInterface;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * FR-LNK-2 collision recovery (design decision 3). A prePersist listener
 * inserts a competing row with the very slug being persisted — after the
 * pre-check, on the same connection, inside the flush transaction — so the
 * unique index rejects the ORM's INSERT. (Under the per-test transaction the
 * competitor is rolled back together with the failed attempt; what matters is
 * that the violation happened.) The processor must reset the closed
 * EntityManager and try the next candidate; five collisions end in a logged 500.
 */
#[CoversClass(CreateLinkProcessor::class)]
final class SlugCollisionRecoveryTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testOneCollisionIsRecoveredWithTheNextCandidate(): void
    {
        $client = self::createClient();
        $client->disableReboot(); // the stubbed generator and the competitor listener live in this container
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $this->stubGenerator(['cand0001', 'cand0002', 'cand0003', 'cand0004', 'cand0005']);
        $this->competeOnFlush(times: 1, ownerId: (string) $owner->getId());
        $token = $this->token($client, 'a@example.com');

        $this->post($client, $token);

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertSame('cand0002', $body['slug'], 'candidate 1 was taken at insert time, the request recovered with candidate 2');

        // recovery evidence: the registry's manager is open and the row exists with the right owner
        $doctrine = self::getContainer()->get(ManagerRegistry::class);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertTrue($em->isOpen());
        $stored = self::getContainer()->get(LinkRepositoryInterface::class)->findBySlug('cand0002');
        self::assertInstanceOf(Link::class, $stored);
        self::assertTrue($stored->getOwner()->getId()->equals($owner->getId()));
        self::assertTrue($em->contains($stored->getOwner()), 'the owner is managed by the current unit of work');
    }

    public function testFiveCollisionsEndIn500WithAnErrorLog(): void
    {
        $client = self::createClient();
        $client->disableReboot(); // the stubbed generator and the competitor listener live in this container
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $this->stubGenerator(['cand0001', 'cand0002', 'cand0003', 'cand0004', 'cand0005']);
        $this->competeOnFlush(times: 5, ownerId: (string) $owner->getId());
        $token = $this->token($client, 'a@example.com');
        $handler = new TestHandler();
        $logger = self::getContainer()->get('logger');
        self::assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($handler);

        $this->post($client, $token);

        self::assertResponseStatusCodeSame(500);
        self::assertTrue($handler->hasErrorThatContains('Could not find a free generated slug'));
        $record = $handler->getRecords()[array_key_last($handler->getRecords())];
        self::assertSame(5, $record->context['attempts']);
    }

    public function testCustomSlugRaceIs422WithoutRetry(): void
    {
        $client = self::createClient();
        $client->disableReboot(); // the stubbed generator and the competitor listener live in this container
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $this->competeOnFlush(times: 1, ownerId: (string) $owner->getId());
        $token = $this->token($client, 'a@example.com');

        $this->post($client, $token, 'mine-2026');

        self::assertResponseStatusCodeSame(422);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('slug', $problem['violations'][0]['propertyPath']);
    }

    /**
     * @param list<string> $candidates
     */
    private function stubGenerator(array $candidates): void
    {
        self::getContainer()->set(SlugGenerator::class, new class($candidates) implements SlugGeneratorInterface {
            /** @param list<string> $candidates */
            public function __construct(private array $candidates)
            {
            }

            public function generate(): string
            {
                return array_shift($this->candidates) ?? throw new \LogicException('out of candidates');
            }
        });
    }

    /**
     * On each of the next $times persists of a Link, insert a competing row
     * with the same slug on the same connection, right before the ORM's INSERT.
     */
    private function competeOnFlush(int $times, string $ownerId): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->getEventManager()->addEventListener([Events::prePersist], new class($times, $ownerId) {
            public function __construct(private int $remaining, private readonly string $ownerId)
            {
            }

            public function prePersist(PrePersistEventArgs $args): void
            {
                $entity = $args->getObject();
                if ($this->remaining <= 0 || !$entity instanceof Link) {
                    return;
                }
                --$this->remaining;
                $om = $args->getObjectManager();
                \assert($om instanceof EntityManagerInterface);
                $om->getConnection()->insert('links', [
                    'id' => (string) Uuid::v7(), 'owner_id' => $this->ownerId, 'slug' => $entity->getSlug(),
                    'target_url' => 'https://competitor.example/', 'click_count' => 0, 'is_active' => true,
                    'created_at' => '2026-09-09 00:00:00+00', 'updated_at' => '2026-09-09 00:00:00+00',
                ], ['is_active' => ParameterType::BOOLEAN]);
            }
        });
    }

    private function token(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $email): string
    {
        $client->jsonRequest('POST', '/api/v1/auth/token', ['email' => $email, 'password' => UserFactory::PASSWORD]);
        $token = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    private function post(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, string $token, ?string $slug = null): void
    {
        $payload = ['targetUrl' => 'https://example.com/x'];
        if (null !== $slug) {
            $payload['slug'] = $slug;
        }
        $client->request('POST', '/api/v1/links', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
