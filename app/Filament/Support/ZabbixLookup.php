<?php

namespace App\Filament\Support;

use App\Zabbix\ZabbixClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Search callbacks for the host / host group pickers.
 *
 * Values are the names stored in the source config (the technical host name
 * for hosts), so existing configs keep working. What was typed is always
 * offered too, so a name can still be entered when Zabbix is unreachable or
 * the host does not exist yet.
 */
class ZabbixLookup
{
    private const MIN_LENGTH = 2;

    /** @return array<string, string> value => label */
    public static function hosts(?string $search): array
    {
        $search = trim((string) $search);

        if (mb_strlen($search) < self::MIN_LENGTH) {
            return [];
        }

        $found = self::attempt(fn (ZabbixClient $z) => collect($z->searchHosts($search, 30))
            ->mapWithKeys(fn (array $h) => [$h['host'] => $h['name'] !== $h['host'] ? "{$h['name']} ({$h['host']})" : $h['host']])
            ->all());

        return self::withTyped($search, $found);
    }

    /** @return array<string, string> value => label */
    public static function hostGroups(?string $search): array
    {
        $search = trim((string) $search);

        if (mb_strlen($search) < self::MIN_LENGTH) {
            return [];
        }

        $found = self::attempt(fn (ZabbixClient $z) => collect($z->searchHostGroups($search, 30))
            ->mapWithKeys(fn (array $g) => [$g['name'] => $g['name']])
            ->all());

        return self::withTyped($search, $found);
    }

    /**
     * Labels for values already saved; no API call, so the form opens even
     * when Zabbix is down.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    public static function labels(array $values): array
    {
        return array_combine($values, $values) ?: [];
    }

    /**
     * @param  array<string, string>|null  $found
     * @return array<string, string>
     */
    private static function withTyped(string $search, ?array $found): array
    {
        $found ??= [];

        if (! array_key_exists($search, $found)) {
            $found[$search] = $found === [] ? "{$search} (not found in Zabbix – use as typed)" : "{$search} (as typed)";
        }

        return $found;
    }

    /** @return array<string, string>|null null when Zabbix could not be asked */
    private static function attempt(callable $lookup): ?array
    {
        try {
            return $lookup(app(ZabbixClient::class));
        } catch (Throwable $e) {
            Log::info('Zabbix lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
