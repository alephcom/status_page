<?php

namespace App\Support;

use App\Models\AuditLog;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Throwable;

class AuditLogger
{
    private const MAX_VALUE_LENGTH = 1000;

    public static function record(string $action, Model $model): void
    {
        if (method_exists($model, 'shouldAudit') && ! $model->shouldAudit()) {
            return;
        }

        $changes = match ($action) {
            'created' => self::attributes($model),
            'updated' => self::diff($model),
            default => null,
        };

        if ($action === 'updated' && $changes === []) {
            return;
        }

        try {
            AuditLog::create([
                'user_id' => auth()->id(),
                'via' => self::via(),
                'action' => $action,
                'subject_type' => class_basename($model),
                'subject_id' => is_numeric($model->getKey()) ? $model->getKey() : null,
                'subject_label' => mb_substr((string) (method_exists($model, 'auditLabel') ? $model->auditLabel() : $model->getKey()), 0, 255),
                'changes' => $changes,
                'ip' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Never let auditing break the change itself.
            report($e);
        }
    }

    /** Where the change came from. */
    public static function via(): string
    {
        if (auth()->guest()) {
            return 'system';
        }

        $request = request();

        return match (true) {
            $request->is('mcp', 'mcp/*') => 'mcp',
            $request->is('api/*') => 'api',
            default => 'panel',
        };
    }

    /** @return array<string, mixed> */
    private static function attributes(Model $model): array
    {
        $out = [];

        foreach ($model->getAttributes() as $key => $value) {
            if (! self::excluded($model, $key) && $value !== null) {
                $out[$key] = self::value($model->getAttribute($key));
            }
        }

        return $out;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    private static function diff(Model $model): array
    {
        $out = [];

        foreach (array_keys($model->getChanges()) as $key) {
            if (self::excluded($model, $key)) {
                continue;
            }

            $old = $model->getOriginal($key);
            $new = $model->getAttribute($key);
            $out[$key] = [self::value($old), self::value($new)];
        }

        return $out;
    }

    private static function excluded(Model $model, string $key): bool
    {
        $exclude = method_exists($model, 'auditExclude') ? $model->auditExclude() : [];

        return in_array($key, ['created_at', 'updated_at', 'id', ...$model->getHidden(), ...$exclude], true);
    }

    private static function value(mixed $value): mixed
    {
        $value = match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            default => $value,
        };

        if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            return mb_substr($value, 0, self::MAX_VALUE_LENGTH).'…';
        }

        return $value;
    }
}
