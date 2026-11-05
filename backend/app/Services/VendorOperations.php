<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class VendorOperations
{
    public static function status(object $vendor): string
    {
        if ($vendor->operating_mode !== 'open') {
            return $vendor->operating_mode;
        }
        $hours = json_decode($vendor->opening_hours ?? 'null', true);
        if ($hours === null) {
            return 'open';
        }
        $now = CarbonImmutable::now('Asia/Kolkata');
        foreach ([$now, $now->subDay()] as $day) {
            $slot = $hours[$day->dayOfWeekIso - 1] ?? null;
            if (! $slot || ! $slot['enabled']) {
                continue;
            }
            $start = $day->setTimeFromTimeString($slot['opens']);
            $end = $day->setTimeFromTimeString($slot['closes']);
            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }
            if ($now->greaterThanOrEqualTo($start) && $now->lessThan($end)) {
                return 'open';
            }
        }

        return 'closed';
    }

    public static function closedIds(): array
    {
        return DB::table('vendors')->where('status', 'approved')->get(['id', 'operating_mode', 'opening_hours'])->filter(fn ($vendor) => self::status($vendor) !== 'open')->pluck('id')->all();
    }

    public static function present(object $vendor): array
    {
        return ['ordering_status' => self::status($vendor), 'preparation_minutes' => $vendor->preparation_minutes, 'opening_hours' => json_decode($vendor->opening_hours ?? 'null', true), 'timezone' => 'Asia/Kolkata'];
    }
}
