<?php

namespace App\Jobs;

use App\Ai\IncidentContext;
use App\Ai\IncidentWriter;
use App\Enums\IncidentImpact;
use App\Models\Incident;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Rewrites an automatically opened draft with AI-written public text. The
 * draft stays hidden: staff still review and publish it. Skipped if someone
 * has already edited or published it.
 */
class DraftAutoIncidentText implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Incident $incident, public string $placeholder) {}

    public function handle(IncidentWriter $writer): void
    {
        $incident = $this->incident->fresh(['components', 'updates']);

        if (! $writer->enabled() || ! $incident || $incident->visible || $incident->isResolved() || $incident->updates->count() !== 1) {
            return;
        }

        $first = $incident->updates->first();

        if ($first->message !== $this->placeholder) {
            return;
        }

        $draft = $writer->draftIncident(IncidentContext::forComponents($incident->components));

        $incident->update([
            'title' => mb_substr($draft['title'], 0, 255),
            'impact' => IncidentImpact::tryFrom($draft['impact']) ?? $incident->impact,
        ]);
        $first->update(['message' => $draft['message']]);
    }
}
