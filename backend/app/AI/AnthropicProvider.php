<?php

namespace App\AI;

use Anthropic\Client;
use App\AI\Concerns\BuildsSuggestions;
use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiNotConfiguredException;
use RuntimeException;
use Throwable;

/**
 * Anthropic-backed provider, using the official `anthropic-ai/sdk`.
 * Construction never touches the network — only healthCheck() and the
 * suggestion methods make a request.
 */
class AnthropicProvider implements AiProvider
{
    use BuildsSuggestions;

    private ?Client $client = null;

    public function __construct(private readonly AiSettings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->usable();
    }

    public function model(): string
    {
        return $this->settings->model;
    }

    public function healthCheck(): string
    {
        if (! $this->isConfigured()) {
            throw AiNotConfiguredException::default();
        }

        try {
            $message = $this->client()->messages->create(
                model: $this->settings->model,
                maxTokens: 16,
                messages: [
                    ['role' => 'user', 'content' => 'Reply with the single word: OK'],
                ],
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Anthropic rejected the health check: {$e->getMessage()}",
                previous: $e,
            );
        }

        $reply = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $reply = trim($block->text);
                break;
            }
        }

        return "Connected — {$this->settings->model} replied ".($reply !== '' ? "\"{$reply}\"." : '(no text).');
    }

    /**
     * @param  array{name: string, description: string, schema: array<string, mixed>}  $tool
     * @return array{0: array<string, mixed>, 1: array{0: int, 1: int}}|null
     */
    protected function structuredCall(string $system, string $user, array $tool): ?array
    {
        try {
            $message = $this->client()->messages->create(
                model: $this->settings->model,
                maxTokens: 700,
                system: $system,
                messages: [['role' => 'user', 'content' => $user]],
                tools: [[
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'input_schema' => $tool['schema'],
                ]],
                toolChoice: ['type' => 'tool', 'name' => $tool['name']],
            );
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'tool_use') {
                $usage = $message->usage ?? null;

                return [
                    (array) $block->input,
                    [(int) ($usage->inputTokens ?? 0), (int) ($usage->outputTokens ?? 0)],
                ];
            }
        }

        return null;
    }

    private function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) $this->settings->apiKey);
    }
}
