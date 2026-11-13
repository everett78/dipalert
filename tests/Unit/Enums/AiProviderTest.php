<?php

use App\Enums\AiProvider;
use Laravel\Ai\Enums\Lab;

it('has the expected string value for every case', function () {
    expect(AiProvider::OpenAI->value)->toBe('openai')
        ->and(AiProvider::Anthropic->value)->toBe('anthropic')
        ->and(AiProvider::Ollama->value)->toBe('ollama')
        ->and(AiProvider::Gemini->value)->toBe('gemini')
        ->and(AiProvider::Groq->value)->toBe('groq')
        ->and(AiProvider::OpenRouter->value)->toBe('openrouter')
        ->and(AiProvider::Mistral->value)->toBe('mistral');
});

it('maps each provider to the matching SDK lab', function () {
    expect(AiProvider::OpenAI->toLab())->toBe(Lab::OpenAI)
        ->and(AiProvider::Anthropic->toLab())->toBe(Lab::Anthropic)
        ->and(AiProvider::Ollama->toLab())->toBe(Lab::Ollama)
        ->and(AiProvider::Gemini->toLab())->toBe(Lab::Gemini)
        ->and(AiProvider::Groq->toLab())->toBe(Lab::Groq)
        ->and(AiProvider::OpenRouter->toLab())->toBe(Lab::OpenRouter)
        ->and(AiProvider::Mistral->toLab())->toBe(Lab::Mistral);
});

it('maps every provider to a lab and a configured driver', function () {
    // This file runs without the application, so read the config file directly.
    $providers = (require dirname(__DIR__, 3).'/config/ai.php')['providers'];

    foreach (AiProvider::cases() as $provider) {
        expect($provider->toLab())->toBeInstanceOf(Lab::class)
            ->and($providers[$provider->driver()] ?? null)->toBeArray();
    }
});

it('exposes the config driver key for each provider', function () {
    expect(AiProvider::OpenAI->driver())->toBe('openai')
        ->and(AiProvider::Anthropic->driver())->toBe('anthropic')
        ->and(AiProvider::Ollama->driver())->toBe('ollama')
        ->and(AiProvider::Gemini->driver())->toBe('gemini')
        ->and(AiProvider::Groq->driver())->toBe('groq')
        ->and(AiProvider::OpenRouter->driver())->toBe('openrouter')
        ->and(AiProvider::Mistral->driver())->toBe('mistral');
});
