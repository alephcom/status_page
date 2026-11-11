<?php

namespace App\Ai;

/**
 * Drafts public-facing incident text. Every draft is reviewed by a person
 * before it is published; nothing written here goes out on its own.
 */
interface IncidentWriter
{
    public function enabled(): bool;

    /**
     * @param  array<string, mixed>  $context  components, active monitoring problems, notes
     * @return array{title: string, message: string, impact: string}
     */
    public function draftIncident(array $context): array;

    /**
     * @param  array<string, mixed>  $context  incident, timeline, monitoring problems
     * @return array{message: string, status: string}
     */
    public function draftUpdate(array $context): array;

    /**
     * @param  array<string, mixed>  $context  incident, timeline, monitoring problems
     */
    public function draftPostmortem(array $context): string;
}
