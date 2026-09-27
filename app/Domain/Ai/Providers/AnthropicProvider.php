<?php

namespace App\Domain\Ai\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Anthropic Messages API over HTTP. Only the grounded facts prepared by the gateway are ever sent. */
final class AnthropicProvider implements AiProvider
{
    public function name(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, string $user, int $maxTokens = 700): ?array
    {
        $config = config('peopleos.ai.anthropic');
        if (blank($config['key'] ?? null)) {
            return null;
        }

        try {
            $response = Http::timeout((int) ($config['timeout'] ?? 20))
                ->withHeaders(['x-api-key' => $config['key'], 'anthropic-version' => $config['version'] ?? '2023-06-01'])
                ->post($config['endpoint'], [
                    'model' => $config['model'],
                    'max_tokens' => min($maxTokens, (int) ($config['max_tokens'] ?? 700)),
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $user]],
                ]);

            if (! $response->successful()) {
                Log::warning('AI provider error', ['status' => $response->status()]);

                return null;
            }

            $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode("\n");

            return $text === '' ? null : [
                'text' => $text,
                'model' => (string) $response->json('model', $config['model']),
                'input_tokens' => (int) $response->json('usage.input_tokens', 0),
                'output_tokens' => (int) $response->json('usage.output_tokens', 0),
            ];
        } catch (\Throwable $e) {
            Log::warning('AI provider unavailable', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
