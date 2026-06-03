<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Providers;

class LMStudioProvider extends AbstractProvider
{
    protected array $pendingToolCalls = [];

    public function getName(): string
    {
        return 'lmstudio';
    }

    public function getDefaultModel(): string
    {
        return 'google/gemma-4-e4b';
    }

    public function getAvailableModels(): array
    {
        return [
            'google/gemma-4-e4b',
        ];
    }

    public function getContextWindowSize(string $model): int
    {
        return match (true) {
            // str_starts_with($model, 'gpt-5.2') => 400_000,
            // str_starts_with($model, 'gpt-5') => 256_000,
            // str_starts_with($model, 'gpt-4o') => 128_000,
            default => 128_000,
        };
    }

    public function getStreamUrl(string $model): string
    {
        $baseUrl = config('prism.providers.lmstudio.url');

        return "{$baseUrl}/chat/completions";
    }

    public function getHeaders(): array
    {
        $apiKey = config('prism.providers.openai.api_key') ?: env('OPENAI_API_KEY');

        return [
            // 'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ];
    }

    public function buildPayload(array $messages, string $model, array $tools = []): array
    {
        $payload = [
            'model' => $model,
            'messages' => $this->formatMessages($messages),
            'stream' => true,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        return $payload;
    }

    protected function formatMessages(array $messages): array
    {
        return array_map(function ($msg) {
            $formatted = [
                'role' => $msg['role'],
                'content' => $msg['content'] ?? '',
            ];

            if (isset($msg['tool_calls'])) {
                $formatted['tool_calls'] = $msg['tool_calls'];
            }

            if (isset($msg['tool_call_id'])) {
                $formatted['tool_call_id'] = $msg['tool_call_id'];
            }

            return $formatted;
        }, $messages);
    }

    protected function processDataLine(string $data, callable $onChunk, callable $onToolCall): void
    {
        $data = trim($data);

        if ($data === '[DONE]' || empty($data)) {
            // Emit any pending tool calls
            foreach ($this->pendingToolCalls as $toolCall) {
                if (! empty($toolCall['function']['name'])) {
                    $onToolCall($toolCall);
                }
            }
            $this->pendingToolCalls = [];

            return;
        }

        $decoded = json_decode($data, true);

        if (! $decoded || ! isset($decoded['choices'][0])) {
            return;
        }

        $choice = $decoded['choices'][0];
        $delta = $choice['delta'] ?? [];

        // Handle text content
        if (isset($delta['content']) && $delta['content'] !== '') {
            $onChunk($delta['content']);
        }

        // Handle tool calls (streamed incrementally)
        if (isset($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $toolCallDelta) {
                $index = $toolCallDelta['index'] ?? 0;

                if (! isset($this->pendingToolCalls[$index])) {
                    $this->pendingToolCalls[$index] = [
                        'id' => '',
                        'type' => 'function',
                        'function' => [
                            'name' => '',
                            'arguments' => '',
                        ],
                    ];
                }

                if (isset($toolCallDelta['id'])) {
                    $this->pendingToolCalls[$index]['id'] = $toolCallDelta['id'];
                }

                if (isset($toolCallDelta['function']['name'])) {
                    $this->pendingToolCalls[$index]['function']['name'] .= $toolCallDelta['function']['name'];
                }

                if (isset($toolCallDelta['function']['arguments'])) {
                    $this->pendingToolCalls[$index]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
                }
            }
        }
    }
}
