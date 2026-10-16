<?php

namespace App\Models;

use Database\Factories\PublicDashboardSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether the wallboard (/wall) is switched on, whether its organisations
 * panel scrolls by itself when it overflows, and which day range it shows
 * when the address doesn't pick one. Only a single row is ever
 * stored; managed under Settings > Public Dashboard. Off until an admin
 * enables it.
 */
class PublicDashboardSetting extends Model
{
    /** @use HasFactory<PublicDashboardSettingFactory> */
    use HasFactory;

    /** The day ranges the wallboard can show. */
    public const array DAY_RANGES = [7, 30, 90];

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

    public static function defaultDays(): int
    {
        $days = static::current()?->default_days;

        return in_array($days, self::DAY_RANGES, true) ? $days : self::DEFAULT_DAYS;
    }

    public static function setDefaultDays(int $days): self
    {
        $setting = static::current() ?? new self;
        $setting->fill(['default_days' => $days])->save();

        return $setting;
    }
}
