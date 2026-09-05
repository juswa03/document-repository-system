<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI agent layer (§F)
    |--------------------------------------------------------------------------
    |
    | These are the DEFAULTS / hard limits. The operable settings — whether
    | the layer is on, which provider and model, the spend cap and the
    | confidence threshold — are stored in `system_settings` and editable
    | by a system admin (see AiSettingController). The API KEY is the one
    | thing that is NOT admin-editable: it lives here, from the environment
    | / secrets manager only.
    |
    | With no key configured the layer is inert regardless of the toggle:
    | AiProvider resolves to NullProvider and nothing is ever sent.
    |
    */

    'enabled' => env('AI_ENABLED', false),

    'provider' => env('AI_PROVIDER', 'anthropic'),

    // Cheapest current model — the default for demonstration. A system
    // admin can switch to a more capable model from `models` below.
    'model' => env('AI_MODEL', 'claude-haiku-4-5'),

    // Hard ceiling on estimated monthly spend (USD). The layer stops
    // calling the provider for the rest of the month once exceeded.
    'monthly_cap_usd' => (float) env('AI_MONTHLY_CAP_USD', 20),

    // A suggestion below this confidence is stored but not surfaced as a
    // recommendation.
    'confidence_threshold' => (float) env('AI_CONFIDENCE_THRESHOLD', 0.6),

    /*
    |--------------------------------------------------------------------------
    | Capabilities (AI-01…09)
    |--------------------------------------------------------------------------
    | Which analyses the layer runs. A system admin can switch any of them
    | off individually (system_settings.ai_capabilities — null means "all
    | on"). `near_duplicate` is deterministic and free; the rest each cost
    | one provider call per document.
    */
    'capabilities' => [
        'classification' => 'Category suggestion',
        'completeness' => 'Completeness assessment',
        'metadata' => 'Metadata extraction',
        'confidentiality' => 'Access-level / confidentiality check',
        'summary' => 'Document summary',
        'near_duplicate' => 'Near-duplicate detection (no provider cost)',
        'search' => 'Natural-language search parsing',
        'report_narrative' => 'Report narrative summary',
    ],

    'providers' => [

        'anthropic' => [
            // SECRET — environment only. Never written to or read from the
            // admin panel.
            'key' => env('ANTHROPIC_API_KEY'),

            'models' => [
                'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5', 'input' => 1.00, 'output' => 5.00],
                'claude-sonnet-5' => ['label' => 'Claude Sonnet 5', 'input' => 2.00, 'output' => 10.00],
                'claude-opus-5' => ['label' => 'Claude Opus 5', 'input' => 5.00, 'output' => 25.00],
            ],
        ],

        // Groq — OpenAI-compatible, free tier, no card. Get a key at
        // https://console.groq.com → API Keys. Prices are 0 here because
        // the free tier has no per-token charge (rate limits only).
        //
        // Groq rotates its hosted models often. Confirm the current list
        // for your key with:
        //   curl -H "Authorization: Bearer $GROQ_API_KEY" \
        //        https://api.groq.com/openai/v1/models
        // and that the model reliably honours a forced tool call — the
        // suggestion features depend on it. As of 2026-09 the gpt-oss
        // models on Groq often refuse a forced tool call; Qwen 3 works.
        'groq' => [
            'key' => env('GROQ_API_KEY'),
            'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
            'models' => [
                'qwen/qwen3.8-27b' => ['label' => 'Qwen 3.8 27B — Groq', 'input' => 0.0, 'output' => 0.0],
                'qwen/qwen3.6-27b' => ['label' => 'Qwen 3.6 27B — Groq', 'input' => 0.0, 'output' => 0.0],
                'openai/gpt-oss-120b' => ['label' => 'GPT-OSS 120B — Groq', 'input' => 0.0, 'output' => 0.0],
            ],
        ],

        // Any other OpenAI-compatible endpoint — a local Ollama
        // (http://localhost:11434/v1), OpenRouter, vLLM, LM Studio.
        // Add the model ids you plan to use.
        'openai_compatible' => [
            'key' => env('OPENAI_COMPAT_API_KEY', 'not-needed'),
            'base_url' => env('OPENAI_COMPAT_BASE_URL'),
            'models' => [
                'llama3.1' => ['label' => 'Llama 3.1 — local', 'input' => 0.0, 'output' => 0.0],
            ],
        ],

    ],

];
