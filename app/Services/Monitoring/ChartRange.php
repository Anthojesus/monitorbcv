<?php

namespace App\Services\Monitoring;

use Carbon\CarbonInterface;

class ChartRange
{
    public const DEFAULT = '15m';

    /**
     * @return array<string, string>
     */
    public static function options(int $maxDays): array
    {
        $maxDays = max(1, min(365, $maxDays));
        $options = [
            '15m' => 'Últimos 15 minutos',
            '1h' => 'Última hora',
            '6h' => 'Últimas 6 horas',
            '24h' => 'Últimas 24 horas',
        ];

        if ($maxDays >= 7) {
            $options['7d'] = 'Últimos 7 días';
        }

        if ($maxDays > 7 || ($maxDays > 1 && $maxDays < 7)) {
            $options['max'] = 'Últimos '.$maxDays.' días';
        }

        return $options;
    }

    public static function since(string $range, int $maxDays): CarbonInterface
    {
        $maxDays = max(1, min(365, $maxDays));
        $floor = now()->subDays($maxDays);
        $candidate = match ($range) {
            '15m' => now()->subMinutes(15),
            '1h' => now()->subHour(),
            '6h' => now()->subHours(6),
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            default => $floor,
        };

        return $candidate->lt($floor) ? $floor : $candidate;
    }

    public static function normalize(string $range, int $maxDays): string
    {
        $options = self::options($maxDays);

        return array_key_exists($range, $options) ? $range : self::DEFAULT;
    }
}
