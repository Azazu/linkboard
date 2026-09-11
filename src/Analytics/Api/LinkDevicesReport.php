<?php

declare(strict_types=1);

namespace App\Analytics\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use App\Analytics\Api\Parameter\FromParameter;
use App\Analytics\Api\Parameter\IncludeBotsParameter;
use App\Analytics\Api\Parameter\ToParameter;
use App\Analytics\Dto\Devices;
use App\Analytics\Dto\DeviceTypeRow;
use App\Analytics\Dto\OsRow;
use App\Analytics\Report\ReportRequest;

/** GET /api/v1/links/{id}/stats/devices (spec analytics "Devices report"). */
#[ApiResource(
    shortName: 'LinkDevicesReport',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/links/{id}/stats/devices',
            uriVariables: ['id' => new Link(fromClass: self::class, identifiers: ['linkId'])],
            provider: LinkReportProvider::class,
            security: 'is_granted("ROLE_USER")',
            parameters: ['from' => new FromParameter(), 'to' => new ToParameter(), 'includeBots' => new IncludeBotsParameter()],
            description: 'The period\'s clicks broken down by device type and, separately, by operating system, each with share of the total; null groups clicks detection did not recognise. Owner or admin.',
        ),
    ],
)]
final readonly class LinkDevicesReport
{
    /**
     * @param list<DeviceTypeRow> $byDeviceType
     * @param list<OsRow>         $byOs
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $linkId,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
        public bool $includeBots,
        public int $total,
        public array $byDeviceType,
        public array $byOs,
        public \DateTimeImmutable $generatedAt,
    ) {
    }

    public static function of(ReportRequest $request, Devices $devices, \DateTimeImmutable $generatedAt): self
    {
        return new self(
            $request->linkId?->toRfc4122() ?? throw new \LogicException('A link report needs a link.'),
            $request->period->from,
            $request->period->to,
            $request->includeBots,
            $devices->total,
            $devices->byDeviceType,
            $devices->byOs,
            $generatedAt,
        );
    }
}
