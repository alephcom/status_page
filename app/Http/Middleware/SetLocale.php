<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public pages: ?lang=xx (remembered in a cookie), then the cookie, then the
 * browser's Accept-Language, then the site default; only enabled languages.
 */
class SetLocale
{
    public const COOKIE = 'statuspage_lang';

    /** Languages with translations shipped in lang/. */
    public const AVAILABLE = [
        'en' => 'English',
        'de' => 'Deutsch',
        'es' => 'Español',
        'fr' => 'Français',
        'nl' => 'Nederlands',
        'sv' => 'Svenska',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $enabled = self::enabled();
        $default = in_array(Setting::get('default_locale'), $enabled, true) ? Setting::get('default_locale') : ($enabled[0] ?? 'en');

        $choice = $request->query('lang');
        $locale = match (true) {
            is_string($choice) && in_array($choice, $enabled, true) => $choice,
            in_array($request->cookie(self::COOKIE), $enabled, true) => $request->cookie(self::COOKIE),
            default => collect($request->getLanguages())
                ->map(fn (string $l) => strtolower(substr($l, 0, 2)))
                ->first(fn (string $l) => in_array($l, $enabled, true)) ?? $default,
        };

        // Accept-Language may name a language we have but did not enable.
        if (! in_array($locale, $enabled, true)) {
            $locale = $default;
        }

        app()->setLocale($locale);

        $response = $next($request);

        if (is_string($choice) && in_array($choice, $enabled, true)) {
            $response->headers->setCookie(cookie()->forever(self::COOKIE, $choice));
        }

        return $response;
    }

    /** @return list<string> */
    public static function enabled(): array
    {
        $enabled = array_values(array_intersect((array) Setting::get('enabled_locales'), array_keys(self::AVAILABLE)));

        return $enabled ?: ['en'];
    }
}
