<?php

namespace Tests\Fakes;

use App\Ai\IncidentWriter;

class FakeIncidentWriter implements IncidentWriter
{
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(public bool $enabled = true) {}

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function draftIncident(array $context): array
    {
        $this->calls[] = ['incident', $context];

        return ['title' => 'Portal is unavailable', 'message' => 'Some users cannot sign in. We are looking into it.', 'impact' => 'major'];
    }

    public function draftUpdate(array $context): array
    {
        $this->calls[] = ['update', $context];

        return ['message' => 'A fix is being rolled out.', 'status' => 'identified'];
    }

    public function draftPostmortem(array $context): string
    {
        $this->calls[] = ['postmortem', $context];

        return "### Summary\nThe portal was down.";
    }
}
