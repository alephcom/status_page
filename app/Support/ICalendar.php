<?php

namespace App\Support;

use App\Models\Maintenance;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/** Planned maintenance as an iCalendar (RFC 5545) feed. */
class ICalendar
{
    /** @param Collection<int, Maintenance> $maintenances */
    public static function render(string $name, Collection $maintenances, string $host): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//status page//maintenance//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text($name),
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
        ];

        foreach ($maintenances as $m) {
            $description = trim(($m->components->isNotEmpty() ? __('Affects').': '.$m->components->pluck('name')->join(', ')."\n\n" : '').$m->message);

            array_push($lines,
                'BEGIN:VEVENT',
                'UID:maintenance-'.$m->id.'@'.$host,
                'DTSTAMP:'.self::time($m->updated_at),
                'LAST-MODIFIED:'.self::time($m->updated_at),
                'DTSTART:'.self::time($m->scheduled_start),
                'DTEND:'.self::time($m->scheduled_end),
                'SUMMARY:'.self::text(__('Maintenance').': '.$m->title),
                'DESCRIPTION:'.self::text($description),
                'URL:'.route('status').'#maintenance-'.$m->id,
                'STATUS:CONFIRMED',
                'TRANSP:TRANSPARENT',
                'END:VEVENT',
            );
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    private static function time(CarbonInterface $at): string
    {
        return $at->copy()->utc()->format('Ymd\THis\Z');
    }

    private static function text(?string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\;', '\,', '\n', '\n'], (string) $value);
    }

    /** Lines longer than 75 octets continue on the next line after a space. */
    private static function fold(string $line): string
    {
        $out = '';
        $current = '';

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > 74) {
                $out .= $current."\r\n ";
                $current = '';
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
