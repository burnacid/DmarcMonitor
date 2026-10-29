<?php

namespace App\Models;

use Database\Factories\DashboardSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Which periods the dashboard offers (7d, 14d, ...) and which one it opens
 * with for users who haven't picked their own default on their profile.
 * Only a single row is ever stored; managed under Settings > Dashboard.
 */
class DashboardSetting extends Model
{
    /** @use HasFactory<DashboardSettingFactory> */
    use HasFactory;

    /** Every period an admin can offer on the dashboard. */
    public const array AVAILABLE_DAY_RANGES = [7, 14, 30, 60, 90, 180, 365];

    /** The periods offered until an admin changes them. */
    public const array DEFAULT_DAY_RANGES = [7, 14, 30, 90];

    public const int DEFAULT_DAYS = 30;

    protected $fillable = ['day_ranges', 'default_days'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day_ranges' => 'array',
            'default_days' => 'integer',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->oldest('id')->first();
    }

    /**
     * The periods the dashboard offers, shortest first.
     *
     * @return array<int, int>
     */
    public static function dayRanges(): array
    {
        $ranges = array_values(array_intersect(self::AVAILABLE_DAY_RANGES, static::current()?->day_ranges ?? []));

        return $ranges !== [] ? $ranges : self::DEFAULT_DAY_RANGES;
    }

    public static function offers(int $days): bool
    {
        return in_array($days, static::dayRanges(), true);
    }

    /**
     * Switches a period on or off. The last remaining period can't be
     * switched off, so the dashboard always has at least one.
     */
    public static function toggleDayRange(int $days): ?self
    {
        if (! in_array($days, self::AVAILABLE_DAY_RANGES, true)) {
            return null;
        }

        $ranges = static::dayRanges();

        if (in_array($days, $ranges, true)) {
            if (count($ranges) === 1) {
                return null;
            }

            $ranges = array_diff($ranges, [$days]);
        } else {
            $ranges[] = $days;
        }

        $setting = static::current() ?? new self;
        $setting->fill(['day_ranges' => array_values(array_intersect(self::AVAILABLE_DAY_RANGES, $ranges))])->save();

        return $setting;
    }

    /**
     * The site-wide default period. Falls back to 30 days, or the closest
     * offered period, when the stored default is no longer offered.
     */
    public static function defaultDays(): int
    {
        $ranges = static::dayRanges();
        $days = static::current()?->default_days ?? self::DEFAULT_DAYS;

        if (in_array($days, $ranges, true)) {
            return $days;
        }

        return collect($ranges)->sortBy(fn (int $range) => abs($range - self::DEFAULT_DAYS))->first();
    }

    public static function setDefaultDays(int $days): ?self
    {
        if (! static::offers($days)) {
            return null;
        }

        $setting = static::current() ?? new self;
        $setting->fill(['default_days' => $days])->save();

        return $setting;
    }
}
