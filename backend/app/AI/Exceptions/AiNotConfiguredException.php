<?php

namespace App\AI\Exceptions;

use RuntimeException;

class AiNotConfiguredException extends RuntimeException
{
    public static function default(): self
    {
        return new self('The AI layer is switched off or no provider API key is configured.');
    }
}
