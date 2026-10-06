<?php

namespace App\Models;

use Database\Factories\ReportBrandingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * The installation-wide look of the PDF reports: an accent colour and a logo
 * (stored on the private local disk). Only a single row is ever stored;
 * managed under Settings > Report Branding.
 */
class ReportBranding extends Model
{
    /** @use HasFactory<ReportBrandingFactory> */
    use HasFactory;

    /** Used when no accent colour has been configured. */
    public const string DEFAULT_ACCENT = '#4f46e5';

    protected $fillable = ['accent_color', 'logo_path'];

    public static function current(): ?self
    {
        return static::query()->oldest('id')->first();
    }

    /**
     * The values a report view needs, with defaults when nothing is configured.
     *
     * @return array{accent: string, accentSoft: string, accentText: string, logo: ?string}
     */
    public static function forReports(): array
    {
        $branding = static::current();
        $accent = $branding?->accent_color ?? self::DEFAULT_ACCENT;

        return [
            'accent' => $accent,
            'accentSoft' => self::mix($accent, '#ffffff', 0.9),
            'accentText' => self::mix($accent, '#000000', 0.15),
            'logo' => $branding?->logoDataUri(),
        ];
    }

    /**
     * The logo as a data URI, since dompdf won't read files from the
     * private disk by URL. Null without a (readable) logo, and for a PNG
     * when GD is missing: dompdf needs GD for PNGs and would otherwise fail
     * the whole report. Pass $forPdf = false for a browser preview.
     */
    public function logoDataUri(bool $forPdf = true): ?string
    {
        $disk = Storage::disk('local');

        if ($this->logo_path === null || ! $disk->exists($this->logo_path)) {
            return null;
        }

        $mimeType = $disk->mimeType($this->logo_path);

        if ($forPdf && $mimeType === 'image/png' && ! self::canEmbedPng()) {
            return null;
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($disk->get($this->logo_path));
    }

    /**
     * Whether dompdf can render PNG images; JPEGs work without GD.
     */
    public static function canEmbedPng(): bool
    {
        return extension_loaded('gd');
    }

    /**
     * Blends $color towards $with by $amount (0 = $color, 1 = $with), for
     * tints and shades of the accent without relying on dompdf's rgba support.
     */
    public static function mix(string $color, string $with, float $amount): string
    {
        $from = sscanf($color, '#%02x%02x%02x');
        $to = sscanf($with, '#%02x%02x%02x');

        return '#'.implode('', array_map(
            fn (int $a, int $b) => sprintf('%02x', (int) round($a + ($b - $a) * $amount)),
            $from,
            $to,
        ));
    }
}
