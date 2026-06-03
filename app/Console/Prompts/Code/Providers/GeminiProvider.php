<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Providers;

class GeminiProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'gemini';
    }

    public function getDefaultModel(): string
    {
        return 'gemini-2.5-pro';
    }

    public function getAvailableModels(): array
    {
        return [
            'gemini-2.5-pro',
            'gemini-2.5-flash',
            'gemini-2.0-flash',
            'gemini-2.0-flash-lite',
        ];
    }

    public function getContextWindowSize(string $model): int
    {
        return match (true) {
            str_contains($model, '2.5') => 1_000_000,
            str_contains($model, '2.0') => 1_000_000,
            str_contains($model, '1.5-pro') => 2_000_000,
            default => 1_000_000,
        };
    }

    public function getStreamUrl(string $model): string
    {
        $baseUrl = config('prism.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/models');
        $apiKey = config('prism.providers.gemini.api_key') ?: env('GEMINI_API_KEY');

        return "{$baseUrl}/{$model}:streamGenerateContent?alt=sse&key={$apiKey}";
    }

    public function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }

    public function buildPayload(array $messages, string $model, array $tools = []): array
    {
        $contents = $this->formatMessages($messages);
        $systemInstruction = $this->extractSystemInstruction($messages);

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'temperature' => 1.0,
                'maxOutputTokens' => 8192,
            ],
        ];

        if ($systemInstruction) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        if (! empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        return $payload;
    }

    protected function formatMessages(array $messages): array
    {
        $contents = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];

            // Skip system messages (handled separately)
            if ($role === 'system') {
                continue;
            }

            // Map roles to Gemini format
            $geminiRole = match ($role) {
                'assistant' => 'model',
                'tool' => 'function',
                default => 'user',
            };

            $parts = [];

            if (isset($msg['content']) && $msg['content'] !== '') {
                $parts[] = ['text' => $msg['content']];
            }

            // Handle tool calls from assistant
            if (isset($msg['tool_calls'])) {
                foreach ($msg['tool_calls'] as $toolCall) {
                    $parts[] = [
                        'functionCall' => [
                            'name' => $toolCall['function']['name'],
                            'args' => json_decode($toolCall['function']['arguments'], true) ?? [],
                        ],
                    ];
                }
            }

            // Handle tool results
            if ($role === 'tool' && isset($msg['tool_call_id'])) {
                $parts = [
                    [
                        'functionResponse' => [
                            'name' => $msg['name'] ?? 'function',
                            'response' => json_decode($msg['content'], true) ?? ['result' => $msg['content']],
                        ],
                    ],
                ];
            }

            if (! empty($parts)) {
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => $parts,
                ];
            }
        }

        return $contents;
    }

    protected function extractSystemInstruction(array $messages): ?string
    {
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                return $msg['content'] ?? null;
            }
        }

        return null;
    }

    protected function formatTools(array $tools): array
    {
        $functionDeclarations = [];

        foreach ($tools as $tool) {
            if (($tool['type'] ?? '') === 'function') {
                $functionDeclarations[] = [
                    'name' => $tool['function']['name'],
                    'description' => $tool['function']['description'] ?? '',
                    'parameters' => $tool['function']['parameters'] ?? ['type' => 'object', 'properties' => []],
                ];
            }
        }

        return [
            ['functionDeclarations' => $functionDeclarations],
        ];
    }

    protected function processDataLine(string $data, callable $onChunk, callable $onToolCall): void
    {
        $data = trim($data);

        if (empty($data)) {
            return;
        }

        $decoded = json_decode($data, true);

        if (! $decoded || ! isset($decoded['candidates'][0])) {
            return;
        }

        $candidate = $decoded['candidates'][0];
        $content = $candidate['content'] ?? [];
        $parts = $content['parts'] ?? [];

        foreach ($parts as $part) {
            // Handle text content
            if (isset($part['text'])) {
                $onChunk($part['text']);
            }

            // Handle function calls
            if (isset($part['functionCall'])) {
                $onToolCall([
                    'id' => 'call_'.uniqid(),
                    'type' => 'function',
                    'function' => [
                        'name' => $part['functionCall']['name'],
                        'arguments' => json_encode($part['functionCall']['args'] ?? []),
                    ],
                ]);
            }
        }
    }
}
