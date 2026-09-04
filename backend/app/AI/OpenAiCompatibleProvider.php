<?php

namespace App\AI;

use App\AI\Concerns\BuildsSuggestions;
use App\AI\Contracts\AiProvider;
use App\AI\Exceptions\AiNotConfiguredException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Any provider that speaks the OpenAI chat-completions format — Groq,
 * OpenRouter, a local Ollama, a self-hosted vLLM. The base URL and model
 * come from config/ai.php; the key from the environment.
 */
class OpenAiCompatibleProvider implements AiProvider
{
    use BuildsSuggestions;

    public function __construct(private readonly AiSettings $settings) {}

    public function isConfigured(): bool
    {
        return $this->settings->usable() && filled($this->settings->baseUrl);
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
            $response = $this->client()->post('/chat/completions', [
                'model' => $this->settings->model,
                'max_tokens' => 16,
                'messages' => [
                    ['role' => 'user', 'content' => 'Reply with the single word: OK'],
                ],
            ])->throw();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "The provider rejected the health check: {$e->getMessage()}",
                previous: $e,
            );
        }

        $reply = trim((string) $response->json('choices.0.message.content', ''));

        return "Connected — {$this->settings->model} replied ".($reply !== '' ? "\"{$reply}\"." : '(no text).');
    }

    /**
     * @param  array{name: string, description: string, schema: array<string, mixed>}  $tool
     * @return array{0: array<string, mixed>, 1: array{0: int, 1: int}}|null
     */
    protected function structuredCall(string $system, string $user, array $tool): ?array
    {
        try {
            $response = $this->client()->post('/chat/completions', [
                'model' => $this->settings->model,
                'max_tokens' => 700,
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'tools' => [[
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['schema'],
                    ],
                ]],
                'tool_choice' => [
                    'type' => 'function',
                    'function' => ['name' => $tool['name']],
                ],
            ])->throw();
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        $arguments = $response->json('choices.0.message.tool_calls.0.function.arguments');
        $decoded = is_string($arguments) ? json_decode($arguments, true) : null;

        if (! is_array($decoded)) {
            return null;
        }

        return [
            $decoded,
            [
                (int) $response->json('usage.prompt_tokens', 0),
                (int) $response->json('usage.completion_tokens', 0),
            ],
        ];
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) $this->settings->baseUrl, '/'))
            ->withToken((string) $this->settings->apiKey)
            ->acceptJson()
            ->timeout(30);
    }
}
