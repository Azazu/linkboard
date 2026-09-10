<?php

declare(strict_types=1);

namespace App\Redirect\Detection;

use App\Click\Visit;
use DeviceDetector\Cache\PSR6Bridge;
use DeviceDetector\ClientHints;
use DeviceDetector\DeviceDetector;
use DeviceDetector\Yaml\Symfony as SymfonyYamlParser;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * FR-RUL-6 over matomo/device-detector (design decision 7): one detector per
 * request, its regex database cached in the local filesystem pool
 * `cache.device_detector` (no Redis, no network on the hot path), YAML read
 * with the installed symfony/yaml. Mapping onto the vocabularies: device names
 * desktop/smartphone/phablet/tablet, any other recognised type → other,
 * unrecognised → null; OS by family iOS/Android/Windows/Mac/GNU-Linux, any other
 * family → other, unknown → null; browser = the client name (type browser) cut
 * to 32 characters. A recognised bot has isBot true and null dimensions.
 */
final class DeviceDetection implements DeviceDetectionInterface
{
    public const int BROWSER_MAX_LENGTH = 32;

    /** @var array<string, string> device-detector device name → vocabulary */
    private const array DEVICE_MAP = ['desktop' => 'desktop', 'smartphone' => 'smartphone', 'phablet' => 'smartphone', 'tablet' => 'tablet'];
    /** @var array<string, string> device-detector OS family → vocabulary */
    private const array OS_FAMILY_MAP = ['iOS' => 'iOS', 'Android' => 'Android', 'Windows' => 'Windows', 'Mac' => 'macOS', 'GNU/Linux' => 'Linux'];

    public function __construct(
        #[Autowire(service: 'cache.device_detector')]
        private readonly CacheItemPoolInterface $pool,
    ) {
    }

    public function detect(Visit $visit): DetectedClient
    {
        if ('' === $visit->userAgent && [] === $visit->clientHints) {
            return DetectedClient::unknown();
        }

        $detector = new DeviceDetector($visit->userAgent, ClientHints::factory($visit->clientHints));
        $detector->setCache(new PSR6Bridge($this->pool));
        $detector->setYamlParser(new SymfonyYamlParser());
        $detector->skipBotDetection(false);
        $detector->discardBotInformation(true);
        $detector->parse();

        if ($detector->isBot()) {
            return new DetectedClient(null, null, null, true);
        }

        $deviceName = $detector->getDeviceName();
        $deviceType = '' === $deviceName ? null : (self::DEVICE_MAP[$deviceName] ?? 'other');

        $family = $detector->getOs('family');
        $family = \is_string($family) ? $family : '';
        $os = ('' === $family || DeviceDetector::UNKNOWN === $family) ? null : (self::OS_FAMILY_MAP[$family] ?? 'other');

        $browser = null;
        if ('browser' === $detector->getClient('type')) {
            $name = $detector->getClient('name');
            if (\is_string($name) && '' !== $name && DeviceDetector::UNKNOWN !== $name) {
                $browser = mb_substr($name, 0, self::BROWSER_MAX_LENGTH);
            }
        }

        return new DetectedClient($deviceType, $os, $browser, false);
    }
}
