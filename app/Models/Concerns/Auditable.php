<?php

namespace App\Models\Concerns;

use App\Support\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Records creates, updates and deletes in the audit log. Writes made with
 * saveQuietly() (e.g. status recomputation) are deliberately not recorded.
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => AuditLogger::record('created', $model));
        static::updated(fn (Model $model) => AuditLogger::record('updated', $model));
        static::deleted(fn (Model $model) => AuditLogger::record('deleted', $model));
    }

    /** Attributes never written to the audit log, on top of hidden ones and timestamps. */
    public function auditExclude(): array
    {
        return [];
    }

    public function shouldAudit(): bool
    {
        return true;
    }
}
