<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class ServicePeriod
{
    public static function months(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $period = trim((string) $value);
        if ($period === '' || strcasecmp($period, 'no') === 0) {
            return null;
        }

        if (preg_match('/^\d+$/u', $period) === 1) {
            $months = (int) $period;
            return $months > 0 ? $months : null;
        }

        $normalized = mb_strtolower((string) preg_replace('/\s+/u', ' ', $period), 'UTF-8');
        if ($normalized === 'mensual') {
            return 1;
        }

        if (preg_match('/^(\d+)\s*mes(?:es)?$/u', $normalized, $matches) === 1) {
            $months = (int) $matches[1];
            return $months > 0 ? $months : null;
        }

        if (preg_match('/^(\d+)\s*a[nñ]o(?:s)?$/u', $normalized, $matches) === 1) {
            $months = (int) $matches[1] * 12;
            return $months > 0 ? $months : null;
        }

        return null;
    }

    public static function label(mixed $value): string
    {
        $months = self::months($value);
        if ($months !== null) {
            return $months === 1 ? 'Mensual' : "{$months} Meses";
        }

        $period = trim((string) ($value ?? ''));
        return $period === '' || strcasecmp($period, 'no') === 0 || is_numeric($period)
            ? ''
            : $period;
    }

    public static function addToDate(string $date, int $months): Carbon
    {
        return Carbon::parse($date)->addMonthsNoOverflow($months);
    }
}
