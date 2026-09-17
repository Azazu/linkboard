<?php

declare(strict_types=1);

namespace App\Tests\Api\Analytics;

use App\Click\Retention\ClickPartitionsCommand;
use App\Click\Retention\ClickRetention;
use App\Shared\Db\Row;
use App\Tests\Factory\LinkFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Support\Json;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Spec analytics "Summary report" after retention: the all-time figures are
 * over the clicks that are still there, and the change of meaning is visible
 * (change stretch-partition-clicks).
 *
 * Retained means present, not "inside the window": a month the cutoff falls in
 * keeps its older rows, and a deployment where the command has never run keeps
 * everything.
 */
#[CoversNothing]
final class RetentionSummaryTest extends AnalyticsApiTestCase
{
    public function testWithoutARetentionRunEveryFigureIsOverTheWholeHistory(): void
    {
        $client = self::createClient();
        $link = $this->linkWithHistory();
        $token = $this->token($client, 'a@example.com');

        $summary = $this->get($client, $token, "/api/v1/links/{$link}/stats/summary?".self::PERIOD);

        self::assertSame(3, Json::int($summary, 'totalClicks'), 'nothing has been dropped, so every click counts');
        self::assertSame(2, Json::int($summary, 'uniqueVisitors'));
        self::assertStringStartsWith((new \DateTimeImmutable('-10 months'))->format('Y-m'), Json::string($summary, 'firstClickAt'));
    }

    public function testAMonthTheCutoffFallsInKeepsItsOlderRows(): void
    {
        $client = self::createClient();
        $link = $this->linkWithHistory();
        $token = $this->token($client, 'a@example.com');

        // a window of 4 months puts the cutoff inside the month of the -4
        // click, which therefore survives although it is older than the cutoff
        $this->maintain($link, months: '4', retention: true);

        $summary = $this->get($client, $token, "/api/v1/links/{$link}/stats/summary?".self::PERIOD);
        self::assertSame(2, Json::int($summary, 'totalClicks'), 'the straddling month kept its click');
        self::assertStringStartsWith((new \DateTimeImmutable('-4 months'))->format('Y-m'), Json::string($summary, 'firstClickAt'));
    }

    public function testAfterAMonthIsDroppedTheAllTimeFiguresAreOverWhatSurvives(): void
    {
        $client = self::createClient();
        $link = $this->linkWithHistory();
        $token = $this->token($client, 'a@example.com');
        $lifetimeBefore = $this->lifetimeCounter($link);

        $this->maintain($link, months: '2', retention: true);

        $summary = $this->get($client, $token, "/api/v1/links/{$link}/stats/summary?".self::PERIOD);
        self::assertSame(1, Json::int($summary, 'totalClicks'), 'only the recent click survives');
        self::assertStringStartsWith((new \DateTimeImmutable('-1 day'))->format('Y-m'), Json::string($summary, 'firstClickAt'), 'the oldest retained click, not the link\'s first ever');

        // the lifetime counter answers a different question and keeps its value:
        // it counts clicks that happened, the summary counts clicks still kept
        self::assertSame($lifetimeBefore, $this->lifetimeCounter($link), 'retention does not rewrite the lifetime counter');
        self::assertGreaterThan(Json::int($summary, 'totalClicks'), $this->lifetimeCounter($link), 'so the two diverge, on purpose');
    }

    public function testAVisitorWhoseEarlierClicksWereDroppedCountsAsNew(): void
    {
        $client = self::createClient();
        $link = $this->linkWithHistory();
        $token = $this->token($client, 'a@example.com');
        self::assertSame(2, Json::int($this->get($client, $token, "/api/v1/links/{$link}/stats/summary?".self::PERIOD), 'uniqueVisitors'));

        $this->maintain($link, months: '2', retention: true);
        // the returning visitor is the one whose only earlier clicks are gone
        self::click(Uuid::fromString($link), (new \DateTimeImmutable())->format(\DATE_ATOM), ['visitor' => str_repeat('b', 64)]);

        $summary = $this->get($client, $token, "/api/v1/links/{$link}/stats/summary?".self::PERIOD);
        self::assertSame(2, Json::int($summary, 'uniqueVisitors'), 'the returning visitor is counted as new, beside the one that survived');
    }

    /**
     * A link with a click ten months back, one four months back and one
     * yesterday; two distinct visitors, the older two sharing one.
     */
    private function linkWithHistory(): string
    {
        $owner = UserFactory::createOne(['email' => 'a@example.com']);
        $link = LinkFactory::createOne(['owner' => $owner, 'slug' => 'kept']);
        $id = $link->getId();

        foreach (['-10 months' => 'b', '-4 months' => 'b', '-1 day' => 'c'] as $when => $visitor) {
            self::click($id, (new \DateTimeImmutable($when))->format(\DATE_ATOM), ['visitor' => str_repeat($visitor, 64)]);
        }
        self::connection()->executeStatement(
            'UPDATE links SET click_count = (SELECT count(*) FROM clicks WHERE link_id = links.id) WHERE id = ?',
            [$id->toRfc4122()],
        );

        return (string) $id;
    }

    private function maintain(string $link, string $months, bool $retention): void
    {
        $connection = self::connection();
        $command = new ClickPartitionsCommand($connection, new ClickRetention($connection, $months, '3'));
        $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), $retention);
    }

    private function lifetimeCounter(string $link): int
    {
        return Row::toInt(self::connection()->fetchOne('SELECT click_count FROM links WHERE id = ?', [$link]));
    }
}
