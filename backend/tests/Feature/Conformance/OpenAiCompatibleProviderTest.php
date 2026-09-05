<?php

namespace Tests\Feature\Conformance;

use App\AI\Contracts\AiProvider;
use App\AI\DocumentContext;
use App\AI\OpenAiCompatibleProvider;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * §F — the AI layer works with a free OpenAI-compatible provider (Groq /
 * OpenRouter / local Ollama), not only Anthropic. The HTTP calls are
 * faked; this checks the wire format and the parsing.
 */
class OpenAiCompatibleProviderTest extends ConformanceTestCase
{
    private function useGroq(): void
    {
        config([
            'ai.providers.groq.key' => 'gsk_test_key',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
        ]);
        SystemSetting::current()->update([
            'ai_enabled' => true,
            'ai_provider' => 'groq',
            'ai_model' => 'qwen/qwen3.8-27b',
        ]);
    }

    private function context(): DocumentContext
    {
        return new DocumentContext(
            title: 'Board resolution 2026-04',
            documentType: 'minutes',
            reportingPeriod: 'q2',
            keywords: 'board',
            description: 'Resolution of the board on the 2026 infrastructure programme.',
            currentCategory: 'Governance',
            accessLevel: 'internal',
        );
    }

    public function test_the_groq_provider_is_selected_when_configured(): void
    {
        $this->useGroq();

        $provider = app(AiProvider::class);

        $this->assertInstanceOf(OpenAiCompatibleProvider::class, $provider);
        $this->assertTrue($provider->isConfigured());
        $this->assertSame('qwen/qwen3.8-27b', $provider->model());
    }

    public function test_it_is_inert_without_a_base_url(): void
    {
        $this->useGroq();
        config(['ai.providers.groq.base_url' => null]);

        $this->assertFalse(app(AiProvider::class)->isConfigured());
    }

    public function test_the_health_check_round_trips(): void
    {
        $this->useGroq();
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'OK']]],
            ]),
        ]);

        $message = app(AiProvider::class)->healthCheck();

        $this->assertStringContainsString('qwen/qwen3.8-27b', $message);
        $this->assertStringContainsString('OK', $message);
    }

    public function test_classify_sends_the_openai_tool_format_and_parses_the_call(): void
    {
        $this->useGroq();
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'tool_calls' => [[
                            'function' => [
                                'name' => 'record_classification',
                                'arguments' => json_encode([
                                    'category' => 'Governance',
                                    'document_type' => 'minutes',
                                    'confidence' => 0.88,
                                    'rationale' => 'Board resolution wording.',
                                ]),
                            ],
                        ]],
                    ],
                ]],
                'usage' => ['prompt_tokens' => 240, 'completion_tokens' => 40],
            ]),
        ]);

        $suggestion = app(AiProvider::class)->classify($this->context(), ['Governance', 'Strategic Planning']);

        $this->assertNotNull($suggestion);
        $this->assertSame('Governance', $suggestion->data['category']);
        $this->assertSame('minutes', $suggestion->data['document_type']);
        $this->assertSame(240, $suggestion->inputTokens);
        $this->assertSame(40, $suggestion->outputTokens);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer gsk_test_key')
                && str_ends_with($request->url(), '/chat/completions')
                && $body['tools'][0]['type'] === 'function'
                && $body['tools'][0]['function']['name'] === 'record_classification'
                && $body['tool_choice']['function']['name'] === 'record_classification';
        });
    }

    public function test_a_provider_error_yields_no_suggestion_rather_than_an_exception(): void
    {
        $this->useGroq();
        Http::fake(['*/chat/completions' => Http::response('rate limited', 429)]);

        $this->assertNull(app(AiProvider::class)->classify($this->context(), ['Governance']));

        // A real (non-connection) API response is never retried — only a
        // failure to connect at all is.
        Http::assertSentCount(1);
    }

    public function test_a_transient_connection_failure_is_retried_and_recovers(): void
    {
        $this->useGroq();
        Sleep::fake();
        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->pushFailedConnection('cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL')
                ->push([
                    'choices' => [['message' => ['content' => 'OK']]],
                ]),
        ]);

        $message = app(AiProvider::class)->healthCheck();

        $this->assertStringContainsString('OK', $message);
        Http::assertSentCount(2);
    }

    public function test_a_persistent_connection_failure_still_yields_no_suggestion(): void
    {
        $this->useGroq();
        Sleep::fake();
        Http::fake([
            '*/chat/completions' => Http::sequence()
                ->pushFailedConnection()
                ->pushFailedConnection()
                ->pushFailedConnection(),
        ]);

        $this->assertNull(app(AiProvider::class)->classify($this->context(), ['Governance']));

        // Initial attempt + 2 retries, then it gives up cleanly.
        Http::assertSentCount(3);
    }

    public function test_a_non_tool_reply_yields_no_suggestion(): void
    {
        $this->useGroq();
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'I cannot help with that.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        $this->assertNull(app(AiProvider::class)->assessCompleteness($this->context()));
    }
}
