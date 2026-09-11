<?php

declare(strict_types=1);

namespace App\Shared\Demo;

use App\Analytics\Cache\ReportCache;
use App\Auth\Entity\User;
use App\Auth\UserRepositoryInterface;
use App\Click\Counter\ClickCounterInterface;
use App\Link\Entity\Link;
use App\Link\Rules\RulesDocumentParser;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Spec demo-data (design decision 10): two accounts with passwords generated
 * for the run and printed once, ten links with routing documents, synthetic
 * click rows generated in SQL with realistic distributions — one transaction,
 * refused in prod and refused twice without --reset. The rows have the
 * handler's shape: no raw IP or user agent anywhere.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Seed demo accounts, links and synthetic clicks (never in prod)')]
final class DemoSeedCommand
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $em,
        private readonly UserRepositoryInterface $users,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly RulesDocumentParser $rulesParser,
        private readonly ClickCounterInterface $counter,
        private readonly ReportCache $reportCache,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Synthetic click records to create in total')] int $clicks = 50000,
        #[Option(description: 'Spread the clicks over the last N days')] int $days = 60,
        #[Option(description: 'Delete the demo accounts (with their links and clicks) first and seed anew')] bool $reset = false,
    ): int {
        if ('prod' === $this->environment) {
            $io->error('app:demo:seed never runs in the prod environment.');

            return Command::FAILURE;
        }
        if ($clicks < 0 || $days < 1) {
            $io->error('--clicks must be 0 or more and --days at least 1.');

            return Command::FAILURE;
        }
        $existing = array_values(array_filter([$this->users->findByEmail(DemoDataset::USER_EMAIL), $this->users->findByEmail(DemoDataset::ADMIN_EMAIL)]));
        if ([] !== $existing && !$reset) {
            $io->error('The demo accounts already exist. Run with --reset to delete them (with their links and clicks) and seed anew.');

            return Command::FAILURE;
        }

        $now = new \DateTimeImmutable();
        $passwords = [DemoDataset::USER_EMAIL => self::password(), DemoDataset::ADMIN_EMAIL => self::password()];
        $formerLinkIds = [];
        /** @var list<Link> $links */
        $links = [];

        $this->connection->transactional(function () use ($existing, $now, $passwords, $clicks, $days, &$formerLinkIds, &$links): void {
            if ([] !== $existing) {
                $formerLinkIds = $this->linkIdsOf($existing);
                foreach ($existing as $user) {
                    $this->em->remove($user); // links and clicks follow through the FK cascades
                }
                $this->em->flush();
            }

            $hasher = $this->hasherFactory->getPasswordHasher(User::class);
            $user = new User(DemoDataset::USER_EMAIL, $hasher->hash($passwords[DemoDataset::USER_EMAIL]), $now);
            $admin = new User(DemoDataset::ADMIN_EMAIL, $hasher->hash($passwords[DemoDataset::ADMIN_EMAIL]), $now);
            $admin->promoteToAdmin($now);
            $this->users->add($user);
            $this->users->add($admin);

            foreach (DemoDataset::links() as $spec) {
                $link = new Link($user, $spec['slug'], $spec['target'], $now);
                $node = json_decode(json_encode($spec['rules'], \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR);
                $document = $this->rulesParser->parse($node)->document ?? throw new \LogicException(\sprintf('The demo document of %s is invalid.', $spec['slug']));
                $link->replaceRules($document->toArray(), $now);
                if (isset($spec['maxClicks'])) {
                    $link->setClickLimit($spec['maxClicks'], $now);
                }
                if (isset($spec['expiresAt'])) {
                    $link->setExpiry($now->modify($spec['expiresAt']), $now);
                }
                if (isset($spec['utm'])) {
                    $link->replaceUtm($spec['utm'], $now);
                }
                $this->em->persist($link);
                $links[] = $link;
            }
            $this->em->flush();

            $remaining = $clicks;
            $specs = DemoDataset::links();
            foreach ($links as $i => $link) {
                $share = $i === \count($links) - 1 ? $remaining : (int) floor($clicks * $specs[$i]['weight'] / 100);
                $remaining -= $share;
                $this->seedClicks($link, $specs[$i], $share, $now->modify(\sprintf('-%d days', $days)), $now);
            }
        });

        foreach ([...$formerLinkIds, ...array_map(static fn (Link $l): string => $l->getId()->toRfc4122(), $links)] as $id) {
            $uuid = Uuid::fromString($id);
            try {
                $this->counter->forget($uuid);
            } catch (\Throwable $e) {
                $this->logger->warning('Click counter key not removed while seeding', ['link_id' => $id, 'exception' => $e::class]);
            }
            $this->reportCache->forgetLink($uuid);
        }
        $this->reportCache->forgetGlobal();

        $io->success(\sprintf('%d links and %d clicks over the last %d days.', \count($links), $clicks, $days));
        $io->writeln('Demo accounts — the passwords are shown once and stored only as hashes (--reset regenerates them):');
        foreach ($passwords as $email => $password) {
            $io->writeln(\sprintf('  %s  password: %s', $email, $password));
        }
        $io->newLine();

        return Command::SUCCESS;
    }

    /**
     * @param array{deviceOs: list<string>, countries: list<string>, language: bool, variants: array<string, int>} $spec
     */
    private function seedClicks(Link $link, array $spec, int $count, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        if ($count < 1) {
            return;
        }
        $q = fn (string $literal): string => $this->connection->quote($literal);
        $in = static fn (array $values): string => implode(', ', array_map($q, $values));

        $whens = [];
        if ([] !== $spec['deviceOs']) {
            $whens[] = \sprintf("WHEN os IN (%s) THEN 'device'", $in($spec['deviceOs']));
        }
        if ([] !== $spec['countries']) {
            $whens[] = \sprintf("WHEN country IN (%s) THEN 'country'", $in($spec['countries']));
        }
        if ($spec['language']) {
            $whens[] = "WHEN r5 < 0.15 THEN 'language'";
        }
        $fallback = [] !== $spec['variants'] ? "'variant'" : "'default'";
        $resolved = [] === $whens ? [$fallback] : ['CASE', ...$whens, 'ELSE '.$fallback.' END'];

        $variant = 'NULL';
        if ([] !== $spec['variants']) {
            $cases = [];
            $cumulative = 0;
            foreach ($spec['variants'] as $name => $weight) {
                $cumulative += $weight;
                $cases[] = \sprintf('WHEN r6 < %d THEN %s', $cumulative, $q((string) $name));
            }
            $variant = \sprintf("CASE WHEN resolved_by = 'variant' THEN CASE %s END END", implode(' ', $cases));
        }

        $sql = <<<SQL
            WITH draw AS (
                SELECT random() AS r1, random() AS r2, random() AS r3, random() AS r4, random() AS r5, random() * 100 AS r6, random() AS r7, random() AS r8
                FROM generate_series(1, :n)
            ), facts AS (
                SELECT
                    CASE WHEN r1 < 0.30 THEN 'DE' WHEN r1 < 0.50 THEN 'US' WHEN r1 < 0.62 THEN 'GB' WHEN r1 < 0.72 THEN 'FR'
                         WHEN r1 < 0.80 THEN 'UA' WHEN r1 < 0.86 THEN 'PL' WHEN r1 < 0.90 THEN 'NL' ELSE NULL END AS country,
                    CASE WHEN r2 < 0.50 THEN 'smartphone' WHEN r2 < 0.90 THEN 'desktop' WHEN r2 < 0.95 THEN 'tablet' WHEN r2 < 0.98 THEN 'desktop' ELSE NULL END AS device_type,
                    CASE WHEN r2 < 0.25 THEN 'iOS' WHEN r2 < 0.50 THEN 'Android' WHEN r2 < 0.80 THEN 'Windows' WHEN r2 < 0.90 THEN 'macOS'
                         WHEN r2 < 0.95 THEN 'iOS' WHEN r2 < 0.98 THEN 'Linux' ELSE NULL END AS os,
                    CASE WHEN r2 < 0.25 THEN 'Mobile Safari' WHEN r2 < 0.50 THEN 'Chrome Mobile' WHEN r2 < 0.80 THEN 'Chrome' WHEN r2 < 0.90 THEN 'Safari'
                         WHEN r2 < 0.95 THEN 'Mobile Safari' WHEN r2 < 0.98 THEN 'Firefox' ELSE NULL END AS browser,
                    r3 < 0.05 AS is_bot,
                    CASE WHEN r4 < 0.40 THEN NULL WHEN r4 < 0.60 THEN 't.co' WHEN r4 < 0.72 THEN 'news.ycombinator.com' WHEN r4 < 0.82 THEN 'www.google.com'
                         WHEN r4 < 0.90 THEN 'www.facebook.com' WHEN r4 < 0.95 THEN 'www.reddit.com' WHEN r4 < 0.98 THEN 'lnkd.in' ELSE 'mail.example.org' END AS referer_host,
                    encode(sha256(convert_to('demo-visitor-' || floor(r7 * :pool)::int::text, 'UTF8')), 'hex') AS visitor_hash,
                    r5, r6, r8
                FROM draw
            ), resolved AS (
                SELECT facts.*, {$this->implode($resolved)} AS resolved_by FROM facts
            )
            INSERT INTO clicks (id, link_id, occurred_at, country, device_type, os, browser, is_bot, referer_host, visitor_hash, variant, resolved_by)
            SELECT gen_random_uuid(), :link_id, (:from)::timestamptz + (r8 * :seconds) * interval '1 second',
                   country, device_type, os, browser, is_bot, referer_host, visitor_hash,
                   {$variant}, resolved_by
            FROM resolved
            SQL;
        $this->connection->executeStatement($sql, [
            'n' => $count,
            'pool' => max(1, intdiv($count, 4)),
            'link_id' => $link->getId()->toRfc4122(),
            'from' => $from,
            'seconds' => $to->getTimestamp() - $from->getTimestamp(),
        ], ['n' => ParameterType::INTEGER, 'pool' => ParameterType::INTEGER, 'from' => Types::DATETIMETZ_IMMUTABLE, 'seconds' => ParameterType::INTEGER]);
        $this->connection->executeStatement('UPDATE links SET click_count = (SELECT count(*) FROM clicks WHERE link_id = links.id) WHERE id = :id', ['id' => $link->getId()->toRfc4122()]);
    }

    /**
     * @param list<string> $parts
     */
    private function implode(array $parts): string
    {
        return implode(' ', $parts);
    }

    /**
     * @param list<User> $users
     *
     * @return list<string>
     */
    private function linkIdsOf(array $users): array
    {
        $ids = array_map(static fn (User $u): string => $u->getId()->toRfc4122(), $users);
        $rows = $this->connection->fetchFirstColumn('SELECT id FROM links WHERE owner_id IN (:ids)', ['ids' => $ids], ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING]);

        return array_map(strval(...), $rows);
    }

    /** 24 hexadecimal characters from a CSPRNG — printed once, stored as a hash. */
    private static function password(): string
    {
        return bin2hex(random_bytes(12));
    }
}
