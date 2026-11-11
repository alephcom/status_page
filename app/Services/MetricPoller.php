<?php

namespace App\Services;

use App\Models\ComponentMetric;
use App\Models\MetricPoint;
use App\Zabbix\ZabbixClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Copies recent history of the configured Zabbix items into metric_points,
 * averaged into fixed buckets, so the public page never queries Zabbix.
 */
class MetricPoller
{
    /** Zabbix value types that hold numbers: float and unsigned integer. */
    private const NUMERIC = [0, 3];

    public function __construct(private readonly ZabbixClient $client) {}

    /** @return array{ok: int, failed: int} */
    public function poll(): array
    {
        $ok = 0;
        $failed = 0;

        ComponentMetric::query()->where('enabled', true)
            ->whereHas('component', fn ($q) => $q->where('enabled', true))
            ->each(function (ComponentMetric $metric) use (&$ok, &$failed) {
                try {
                    $this->pollOne($metric);
                    $ok++;
                } catch (Throwable $e) {
                    $failed++;
                    $metric->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->saveQuietly();
                }
            });

        return ['ok' => $ok, 'failed' => $failed];
    }

    public function pollOne(ComponentMetric $metric): void
    {
        if (! $metric->itemid) {
            $item = $this->client->findItem($metric->host, $metric->item_key);

            if ($item === null) {
                throw new \RuntimeException("Item {$metric->item_key} not found on host {$metric->host}.");
            }

            if (! in_array($item['value_type'], self::NUMERIC, true)) {
                throw new \RuntimeException("Item {$metric->item_key} is not numeric.");
            }

            $metric->forceFill([
                'itemid' => $item['itemid'],
                'value_type' => $item['value_type'],
                'unit' => $metric->unit ?: ($item['units'] ?: null),
            ])->saveQuietly();
        }

        $bucket = max(1, (int) config('statuspage.metrics.bucket_minutes')) * 60;
        $hours = max(1, (int) config('statuspage.metrics.hours'));
        $now = CarbonImmutable::now();

        // Re-read the latest stored bucket too: it may have been partial.
        $last = $metric->points()->max('recorded_at');
        $from = max($now->subHours($hours)->getTimestamp(), $last ? CarbonImmutable::parse($last)->getTimestamp() : 0);
        $from -= $from % $bucket;

        $samples = collect($this->client->history($metric->itemid, (int) $metric->value_type, $from));

        $this->store($metric, $samples, $bucket);

        $metric->forceFill(['last_polled_at' => $now, 'last_error' => null])->saveQuietly();
    }

    /**
     * @param  Collection<int, array{clock: int, value: float}>  $samples
     */
    private function store(ComponentMetric $metric, Collection $samples, int $bucket): void
    {
        $rows = $samples
            ->groupBy(fn (array $s) => $s['clock'] - $s['clock'] % $bucket)
            ->map(fn (Collection $group, int $start) => [
                'component_metric_id' => $metric->id,
                'recorded_at' => CarbonImmutable::createFromTimestamp($start)->setTimezone(config('app.timezone'))->toDateTimeString(),
                'value' => round($group->avg('value'), 6),
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            MetricPoint::query()->upsert($rows, ['component_metric_id', 'recorded_at'], ['value']);
        }
    }
}
