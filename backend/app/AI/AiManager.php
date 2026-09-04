<?php

namespace App\AI;

use App\AI\Contracts\AiProvider;

/**
 * Chooses the concrete AiProvider for the current settings. Resolved
 * fresh from the container each time (bound, not singleton) so an admin
 * changing the settings takes effect on the next request without a
 * restart.
 */
class AiManager
{
    public static function resolve(AiSettings $settings): AiProvider
    {
        if (! $settings->usable()) {
            return new NullProvider($settings->model);
        }

        return match ($settings->provider) {
            'anthropic' => new AnthropicProvider($settings),
            'groq', 'openai_compatible' => new OpenAiCompatibleProvider($settings),
            default => new NullProvider($settings->model),
        };
    }
}
