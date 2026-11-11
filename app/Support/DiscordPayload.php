<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Turns a webhook event into a Discord "execute webhook" body with one
 * embed. https://discord.com/developers/docs/resources/webhook
 */
class DiscordPayload
{
    private const COLORS = [
        'ok' => 0x1F9D55, 'warning' => 0xD69E2E, 'danger' => 0xC53030, 'info' => 0x3182CE, 'muted' => 0x6B7280,
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function make(string $event, array $data): array
    {
        $embed = match (true) {
            str_starts_with($event, 'incident.') => self::incident($event, $data),
            str_starts_with($event, 'maintenance.') => self::maintenance($event, $data),
            str_starts_with($event, 'component.') => self::component($event, $data),
            default => ['title' => $data['message'] ?? $event, 'color' => self::COLORS['muted']],
        };

        return [
            'username' => mb_substr((string) Setting::get('site_name'), 0, 80),
            'allowed_mentions' => ['parse' => []],
            'embeds' => [array_filter($embed + ['timestamp' => now()->toIso8601String()], fn ($v) => $v !== null && $v !== [])],
        ];
    }

    /** @param array<string, mixed> $d */
    private static function incident(string $event, array $d): array
    {
        $update = $d['update'] ?? ($d['updates'][0] ?? null);
        $status = $update['status'] ?? $d['status'] ?? null;

        $prefix = match ($event) {
            'incident.created' => 'New incident',
            'incident.resolved' => 'Resolved',
            default => 'Update',
        };

        return [
            'title' => self::limit("{$prefix}: {$d['title']}", 256),
            'url' => $d['url'] ?? null,
            'description' => self::limit((string) ($update['message'] ?? ''), 4000),
            'color' => match ($status) {
                'resolved' => self::COLORS['ok'],
                'monitoring' => self::COLORS['info'],
                'identified' => self::COLORS['warning'],
                default => self::COLORS['danger'],
            },
            'fields' => array_values(array_filter([
                $status ? ['name' => 'Status', 'value' => ucfirst($status), 'inline' => true] : null,
                isset($d['impact']) ? ['name' => 'Impact', 'value' => ucfirst($d['impact']), 'inline' => true] : null,
                ! empty($d['components']) ? ['name' => 'Affected', 'value' => self::limit(collect($d['components'])->pluck('name')->join(', '), 1024)] : null,
            ])),
        ];
    }

    /** @param array<string, mixed> $d */
    private static function maintenance(string $event, array $d): array
    {
        $prefix = match ($event) {
            'maintenance.started' => 'Maintenance started',
            'maintenance.completed' => 'Maintenance completed',
            default => 'Maintenance scheduled',
        };

        return [
            'title' => self::limit("{$prefix}: {$d['title']}", 256),
            'url' => route('status'),
            'description' => self::limit((string) ($d['message'] ?? ''), 4000),
            'color' => $event === 'maintenance.completed' ? self::COLORS['ok'] : self::COLORS['info'],
            'fields' => array_values(array_filter([
                ['name' => 'Starts', 'value' => self::time($d['scheduled_start']), 'inline' => true],
                ['name' => 'Ends', 'value' => self::time($d['scheduled_end']), 'inline' => true],
                ! empty($d['components']) ? ['name' => 'Affected', 'value' => self::limit(collect($d['components'])->pluck('name')->join(', '), 1024)] : null,
            ])),
        ];
    }

    /** @param array<string, mixed> $d */
    private static function component(string $event, array $d): array
    {
        if ($event === 'component.override_changed') {
            $override = $d['manual_override'] ?? null;

            return [
                'title' => self::limit($override ? "{$d['name']}: status set to ".str_replace('_', ' ', $override['status']) : "{$d['name']}: manual status cleared", 256),
                'url' => route('status'),
                'color' => self::COLORS['muted'],
            ];
        }

        return [
            'title' => self::limit("{$d['name']} is now {$d['status_label']}", 256),
            'url' => route('status'),
            'description' => isset($d['previous_status']) ? 'Was '.str_replace('_', ' ', $d['previous_status']).'.' : null,
            'color' => match ($d['status']) {
                'operational' => self::COLORS['ok'],
                'degraded', 'partial_outage' => self::COLORS['warning'],
                'maintenance' => self::COLORS['info'],
                default => self::COLORS['danger'],
            },
        ];
    }

    /** Discord renders <t:unix:F> in each reader's timezone. */
    private static function time(string $iso): string
    {
        return '<t:'.strtotime($iso).':f>';
    }

    private static function limit(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
