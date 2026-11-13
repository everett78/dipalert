<?php

namespace App\Enums;

use Laravel\Ai\Enums\Lab;

enum AiProvider: string
{
    case OpenAI = 'openai';
    case Anthropic = 'anthropic';
    case Ollama = 'ollama';
    case Gemini = 'gemini';
    case Groq = 'groq';
    case OpenRouter = 'openrouter';
    case Mistral = 'mistral';

    /**
     * Map this provider to the Laravel AI SDK provider enum.
     */
    public function toLab(): Lab
    {
        return match ($this) {
            self::OpenAI => Lab::OpenAI,
            self::Anthropic => Lab::Anthropic,
            self::Ollama => Lab::Ollama,
            self::Gemini => Lab::Gemini,
            self::Groq => Lab::Groq,
            self::OpenRouter => Lab::OpenRouter,
            self::Mistral => Lab::Mistral,
        };
    }

    /**
     * The config key for this provider under `config('ai.providers.*')`.
     */
    public function driver(): string
    {
        return $this->value;
    }
}
