<?php

namespace App\Models;

use Database\Factories\PublicDashboardSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether the wallboard (/wall) is switched on, whether its organisations
 * panel scrolls by itself when it overflows, and which day range it shows
 * when the address doesn't pick one. The ranges offered are the periods
 * enabled for the dashboard (see DashboardSetting). Only a single row is ever
 * stored; managed under Settings > Public Dashboard. Off until an admin
 * enables it.
 */
class PublicDashboardSetting extends Model
{
    /** @use HasFactory<PublicDashboardSettingFactory> */
    use HasFactory;

    public const int DEFAULT_DAYS = 30;

    protected $fillable = ['is_enabled', 'auto_scroll', 'default_days'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'auto_scroll' => 'boolean',
            'default_days' => 'integer',
        ];
    }

    public static function current(): ?self
    {
        return static::query()->oldest('id')->first();
    }

    public static function isEnabled(): bool
    {
        return static::current()?->is_enabled ?? false;
    }

    public static function setEnabled(bool $isEnabled): self
    {
        $setting = static::current() ?? new self;
        $setting->fill(['is_enabled' => $isEnabled])->save();

        return $setting;
    }

    public static function autoScrolls(): bool
    {
        return static::current()?->auto_scroll ?? true;
    }

    public static function setAutoScroll(bool $autoScroll): self
    {
        $setting = static::current() ?? new self;
        $setting->fill(['auto_scroll' => $autoScroll])->save();

        return $setting;
    }

    /**
     * The ranges the wallboard offers: the periods enabled for the dashboard.
     *
     * @return array<int, int>
     */
    public static function dayRanges(): array
    {
        return DashboardSetting::dayRanges();
    }

    public static function offers(int $days): bool
    {
        return in_array($days, static::dayRanges(), true);
    }

    /**
     * The wallboard's default range. Falls back to 30 days, or the closest
     * offered range, when the stored default is no longer offered.
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
