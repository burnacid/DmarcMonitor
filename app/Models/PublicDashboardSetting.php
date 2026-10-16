<?php

namespace App\Models;

use Database\Factories\PublicDashboardSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Whether the wallboard (/wall) is switched on, and whether its organisations
 * panel scrolls by itself when it overflows. Only a single row is ever
 * stored; managed under Settings > Public Dashboard. Off until an admin
 * enables it.
 */
class PublicDashboardSetting extends Model
{
    /** @use HasFactory<PublicDashboardSettingFactory> */
    use HasFactory;

    protected $fillable = ['is_enabled', 'auto_scroll'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'auto_scroll' => 'boolean',
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
}
