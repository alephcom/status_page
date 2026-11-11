<?php

namespace App\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\AnthropicException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * IncidentWriter backed by the Claude API. Enabled when ANTHROPIC_API_KEY
 * is set. Uses structured outputs so the reply is always valid JSON.
 */
class ClaudeIncidentWriter implements IncidentWriter
{
    private const SYSTEM = <<<'TXT'
        You write text for a public service status page, read by customers and end users who are not
        engineers. Be calm, plain and factual. Never speculate about causes that the information does
        not support, never promise times for a fix, and never blame people or suppliers.

        The monitoring data you receive (Zabbix trigger names, host names, item keys) is internal. Do not
        copy host names, IP addresses, trigger expressions or other internal identifiers into the text;
        describe the effect on the service instead, using the public component names.

        Write in the language given by "locale". Use short paragraphs; markdown is allowed but keep it
        simple (no headings in incident updates).
        TXT;

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
    ) {}

    public static function fromConfig(): self
    {
        return new self(config('services.anthropic.key'), (string) config('services.anthropic.model'));
    }

    public function enabled(): bool
    {
        return filled($this->apiKey);
    }

    public function draftIncident(array $context): array
    {
        return $this->json(
            "Draft a new incident for the status page from this information.\n\n".$this->encode($context),
            [
                'title' => ['type' => 'string', 'description' => 'Short public headline, under 80 characters, e.g. "Login failures on the customer portal".'],
                'message' => ['type' => 'string', 'description' => 'First public update, 1–3 sentences: what users notice and that it is being looked at.'],
                'impact' => ['type' => 'string', 'enum' => ['none', 'minor', 'major', 'critical'], 'description' => 'minor = degraded or partial; major = a service is down; critical = several key services are down.'],
            ],
        );
    }

    public function draftUpdate(array $context): array
    {
        return $this->json(
            "Draft the next public update for this incident, based on its timeline and what monitoring reports now.\n\n".$this->encode($context),
            [
                'message' => ['type' => 'string', 'description' => '1–3 sentences. Do not repeat earlier updates word for word.'],
                'status' => ['type' => 'string', 'enum' => ['investigating', 'identified', 'monitoring', 'resolved'], 'description' => 'The status this update moves the incident to.'],
            ],
        );
    }

    public function draftPostmortem(array $context): string
    {
        $out = $this->json(
            <<<'TXT'
            Draft a public post-incident review for this resolved incident, in markdown, with these sections
            as level-3 headings: Summary, Impact, Timeline, Root cause, What we are doing to prevent this.
            Use the timestamps from the timeline. Where the information does not say what the root cause
            was or what follow-up is planned, write a clearly marked placeholder like "[To be completed]"
            for staff to fill in instead of guessing.


            TXT.$this->encode($context),
            ['postmortem' => ['type' => 'string', 'description' => 'The full review in markdown.']],
            maxTokens: 16000,
        );

        return $out['postmortem'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private function json(string $prompt, array $properties, int $maxTokens = 8000): array
    {
        if (! $this->enabled()) {
            throw new AiException('AI drafting is not configured (ANTHROPIC_API_KEY).');
        }

        try {
            $message = (new Client(apiKey: $this->apiKey))->beta->messages->create(
                model: $this->model,
                maxTokens: $maxTokens,
                system: self::SYSTEM,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: [
                    'effort' => 'low',
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => [
                            'type' => 'object',
                            'properties' => $properties,
                            'required' => array_keys($properties),
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                // If the model declines, let the API retry on its default fallback model.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException $e) {
            throw new AiException('The Claude API key was rejected; check ANTHROPIC_API_KEY.', previous: $e);
        } catch (RateLimitException $e) {
            throw new AiException('The Claude API is rate limiting requests; try again in a minute.', previous: $e);
        } catch (AnthropicException $e) {
            throw new AiException('Claude API request failed: '.$e->getMessage(), previous: $e);
        }

        if ($message->stopReason === 'refusal') {
            throw new AiException('Claude declined to draft this text.');
        }

        if ($message->stopReason === 'max_tokens') {
            throw new AiException('The draft was cut off; try again.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $data = json_decode($block->text, true);

                if (is_array($data)) {
                    return $data;
                }
            }
        }

        throw new AiException('Claude returned no usable draft.');
    }

    /** @param array<string, mixed> $context */
    private function encode(array $context): string
    {
        return json_encode($context + ['locale' => config('app.locale')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
