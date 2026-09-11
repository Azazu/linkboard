<?php

declare(strict_types=1);

namespace App\Shared\Demo;

/**
 * What `app:demo:seed` creates (design decision 10): two accounts, ten links
 * with routing documents, and the shape of the synthetic clicks. Constants
 * only — no credential: passwords are generated per run.
 */
final class DemoDataset
{
    public const string USER_EMAIL = 'demo@example.com';
    public const string ADMIN_EMAIL = 'admin@example.com';

    private function __construct()
    {
    }

    /**
     * Ten links; `weight` is the link's share of the seeded clicks (sums to
     * 100), `variants` the names and weights the clicks are assigned by when
     * the document has variants, `deviceOs` / `countries` the values the
     * synthetic clicks treat as matched by the document's rules, `language`
     * whether a language rule exists (a random share resolves by it — the
     * click record carries no language).
     *
     * @return list<array{slug: string, target: string, rules: array<string, mixed>, weight: int, deviceOs: list<string>, countries: list<string>, language: bool, variants: array<string, int>, maxClicks?: int, expiresAt?: string, utm?: array<string, string>}>
     */
    public static function links(): array
    {
        $ios = ['match' => ['device' => ['smartphone', 'tablet'], 'os' => ['iOS']], 'target' => 'https://apps.apple.com/app/id6443210987'];
        $android = ['match' => ['os' => ['Android']], 'target' => 'https://play.google.com/store/apps/details?id=com.example.linkboard'];

        return [
            ['slug' => 'app-download', 'target' => 'https://example.com/app', 'weight' => 22, 'deviceOs' => ['iOS', 'Android'], 'countries' => [], 'language' => false, 'variants' => [],
                'rules' => ['version' => 1, 'rules' => [$ios, $android]]],
            ['slug' => 'spring-sale', 'target' => 'https://example.com/sale', 'weight' => 18, 'deviceOs' => [], 'countries' => [], 'language' => false, 'variants' => ['A' => 50, 'B' => 50],
                'rules' => ['version' => 1, 'variants' => [['name' => 'A', 'weight' => 50, 'target' => 'https://example.com/sale/a'], ['name' => 'B', 'weight' => 50, 'target' => 'https://example.com/sale/b']]],
                'utm' => ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'spring']],
            ['slug' => 'eu-landing', 'target' => 'https://example.com/', 'weight' => 12, 'deviceOs' => [], 'countries' => ['DE', 'AT', 'CH', 'FR'], 'language' => false, 'variants' => [],
                'rules' => ['version' => 1, 'rules' => [['match' => ['country' => ['DE', 'AT', 'CH']], 'target' => 'https://example.de/'], ['match' => ['country' => ['FR']], 'target' => 'https://example.fr/']]]],
            ['slug' => 'docs', 'target' => 'https://docs.example.com/', 'weight' => 10, 'deviceOs' => [], 'countries' => [], 'language' => true, 'variants' => [],
                'rules' => ['version' => 1, 'rules' => [['match' => ['language' => ['uk', 'ru']], 'target' => 'https://docs.example.com/ua/'], ['match' => ['language' => ['de']], 'target' => 'https://docs.example.com/de/']]]],
            ['slug' => 'promo-limited', 'target' => 'https://example.com/promo', 'weight' => 8, 'deviceOs' => [], 'countries' => [], 'language' => false, 'variants' => ['control' => 50, 'hero' => 50], 'maxClicks' => 5000,
                'rules' => ['version' => 1, 'variants' => [['name' => 'control', 'weight' => 50, 'target' => 'https://example.com/promo'], ['name' => 'hero', 'weight' => 50, 'target' => 'https://example.com/promo/hero']]]],
            ['slug' => 'newsletter', 'target' => 'https://example.com/news', 'weight' => 8, 'deviceOs' => [], 'countries' => [], 'language' => true, 'variants' => [],
                'rules' => ['version' => 1, 'rules' => [['match' => ['language' => ['de']], 'target' => 'https://example.com/de/news']]],
                'utm' => ['utm_source' => 'newsletter', 'utm_medium' => 'email']],
            ['slug' => 'black-friday', 'target' => 'https://example.com/bf', 'weight' => 6, 'deviceOs' => [], 'countries' => [], 'language' => false, 'variants' => ['A' => 50, 'B' => 50], 'expiresAt' => '-10 days',
                'rules' => ['version' => 1, 'variants' => [['name' => 'A', 'weight' => 50, 'target' => 'https://example.com/bf/a'], ['name' => 'B', 'weight' => 50, 'target' => 'https://example.com/bf/b']]]],
            ['slug' => 'webinar', 'target' => 'https://example.com/webinar', 'weight' => 6, 'deviceOs' => ['iOS', 'Android'], 'countries' => [], 'language' => false, 'variants' => [],
                'rules' => ['version' => 1, 'rules' => [['match' => ['device' => ['smartphone', 'tablet']], 'target' => 'https://example.com/webinar/mobile']]]],
            ['slug' => 'support', 'target' => 'https://support.example.com/', 'weight' => 5, 'deviceOs' => [], 'countries' => ['DE', 'PL', 'UA'], 'language' => true, 'variants' => [],
                'rules' => ['version' => 1, 'rules' => [['match' => ['country' => ['DE']], 'target' => 'https://support.example.com/de/'], ['match' => ['country' => ['PL', 'UA']], 'target' => 'https://support.example.com/east/'], ['match' => ['language' => ['fr']], 'target' => 'https://support.example.com/fr/']]]],
            ['slug' => 'beta-signup', 'target' => 'https://example.com/beta', 'weight' => 5, 'deviceOs' => [], 'countries' => [], 'language' => false, 'variants' => ['A' => 40, 'B' => 30, 'C' => 30], 'maxClicks' => 1000,
                'rules' => ['version' => 1, 'variants' => [['name' => 'A', 'weight' => 40, 'target' => 'https://example.com/beta/a'], ['name' => 'B', 'weight' => 30, 'target' => 'https://example.com/beta/b'], ['name' => 'C', 'weight' => 30, 'target' => 'https://example.com/beta/c']]]],
        ];
    }
}
