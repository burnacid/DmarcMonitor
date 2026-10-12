<?php

namespace App\Models;

use Database\Factories\DashboardAllowedIpFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An IP address or CIDR range allowed to open the public (unauthenticated)
 * overview, e.g. a wall screen. Managed under Settings > Public Dashboard.
 */
class DashboardAllowedIp extends Model
{
    /** @use HasFactory<DashboardAllowedIpFactory> */
    use HasFactory;

    protected $fillable = ['address', 'description'];

    /**
     * Whether $ip matches any configured address. Nothing is allowed while
     * the list is empty.
     */
    public static function allows(?string $ip): bool
    {
        $addresses = static::query()->pluck('address')->all();

        if ($ip === null || $addresses === []) {
            return false;
        }

        return IpUtils::checkIp($ip, $addresses);
    }
}
