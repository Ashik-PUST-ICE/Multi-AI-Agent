<?php

namespace App\Tools;

use RuntimeException;

final class SmartHomeSafetyPolicy
{
    private const NEVER_AUTO_DEVICE_TERMS = [
        'lock',
        'door lock',
        'stove',
        'gas',
        'oven',
    ];

    public function assertAutomaticActionAllowed(string $deviceType): void
    {
        $normalizedDeviceType = mb_strtolower(trim($deviceType));
        foreach (self::NEVER_AUTO_DEVICE_TERMS as $term) {
            if (str_contains($normalizedDeviceType, $term)) {
                throw new RuntimeException("Automatic actions are prohibited for Never-Auto device type [{$deviceType}].");
            }
        }

        throw new RuntimeException('Smart-home device actions are disabled until an owner-approved safety integration is available.');
    }
}
