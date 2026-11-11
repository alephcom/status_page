<?php

namespace App\Telegram;

use App\Models\Setting;
use App\Notifications\StatusNotification;

/** Renders notifications as Telegram HTML (the only markup we need: bold and links). */
class TelegramMessage
{
    public static function fromNotification(StatusNotification $n): string
    {
        $parts = ['<b>'.self::escape(Setting::get('site_name')).'</b>', '<b>'.self::escape($n->subject).'</b>'];

        foreach ($n->lines as $line) {
            $parts[] = self::markdown($line);
        }

        if ($n->actionUrl) {
            $parts[] = '<a href="'.self::escape($n->actionUrl).'">'.self::escape($n->actionText ?? 'View status page').'</a>';
        }

        return self::limit(implode("\n\n", $parts));
    }

    public static function escape(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape, then turn **bold** into <b>; other markdown is left as plain text. */
    public static function markdown(string $text): string
    {
        return preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', self::escape($text));
    }

    /** Telegram rejects messages over 4096 characters. */
    private static function limit(string $html): string
    {
        if (mb_strlen(strip_tags($html)) <= 4000) {
            return $html;
        }

        return self::escape(mb_substr(html_entity_decode(strip_tags($html)), 0, 3990)).'…';
    }
}
