<?php

declare(strict_types=1);

namespace App\Redirect\Detection;

use App\Click\Visit;

interface DeviceDetectionInterface
{
    /** Never called for a hostile Visit (design decision 5). May throw; the redirect guard degrades. */
    public function detect(Visit $visit): DetectedClient;
}
