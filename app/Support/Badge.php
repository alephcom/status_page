<?php

namespace App\Support;

use App\Enums\ComponentStatus;

/** A shields.io-style two-part SVG badge. */
class Badge
{
    public const COLORS = [
        'operational' => '#1f9d55',
        'degraded' => '#b7791f',
        'partial_outage' => '#c05621',
        'major_outage' => '#c53030',
        'maintenance' => '#2b6cb0',
    ];

    public static function svg(string $label, string $message, ComponentStatus $status, bool $square = false): string
    {
        $lw = self::width($label);
        $mw = self::width($message);
        $w = $lw + $mw;
        $color = self::COLORS[$status->value];
        $r = $square ? 0 : 3;
        $l = e($label);
        $m = e($message);
        $lx = $lw / 2;
        $mx = $lw + $mw / 2;

        return <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" width="{$w}" height="20" role="img" aria-label="{$l}: {$m}">
            <title>{$l}: {$m}</title>
            <linearGradient id="s" x2="0" y2="100%"><stop offset="0" stop-color="#bbb" stop-opacity=".1"/><stop offset="1" stop-opacity=".1"/></linearGradient>
            <clipPath id="r"><rect width="{$w}" height="20" rx="{$r}" fill="#fff"/></clipPath>
            <g clip-path="url(#r)"><rect width="{$lw}" height="20" fill="#555"/><rect x="{$lw}" width="{$mw}" height="20" fill="{$color}"/><rect width="{$w}" height="20" fill="url(#s)"/></g>
            <g fill="#fff" text-anchor="middle" font-family="Verdana,Geneva,DejaVu Sans,sans-serif" font-size="11">
            <text x="{$lx}" y="15" fill="#010101" fill-opacity=".3">{$l}</text><text x="{$lx}" y="14">{$l}</text>
            <text x="{$mx}" y="15" fill="#010101" fill-opacity=".3">{$m}</text><text x="{$mx}" y="14">{$m}</text>
            </g>
            </svg>
            SVG;
    }

    /** Rough Verdana 11px width; good enough for a badge. */
    private static function width(string $text): int
    {
        return (int) ceil(mb_strlen($text) * 6.8) + 12;
    }
}
