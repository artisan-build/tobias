<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Providers;

class AnthropicProvider extends AbstractProvider
{
    public function getName(): string
    {
        return 'anthropic';
    }

    public function getDefaultModel(): string
    {
        return 'claude-sonnet-4-20250514';
    }

    public function getAvailableModels(): array
    {
        return [
            'claude-sonnet-4-20250514',
            'claude-opus-4-20250514',
            'claude-3-5-sonnet-latest',
            'claude-3-5-haiku-latest',
        ];
    }

    public function getContextWindowSize(string $model): int
    {
        return match (true) {
            str_contains($model, 'opus') => 200_000,
            str_contains($model, 'haiku') => 200_000,
            default => 200_000,
        };
    }

    public function getStreamUrl(string $model): string
    {
        $baseUrl = config('prism.providers.anthropic.url', 'https://api.anthropic.com/v1');

        return "{$baseUrl}/messages";
    }

    public function getHeaders(): array
    {
        $apiKey = config('prism.providers.anthropic.api_key') ?: env('ANTHROPIC_API_KEY');
        $version = config('prism.providers.anthropic.version', '2023-06-01');

        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => $version,
            'Content-Type' => 'application/json',
        ];
    }

    public function buildPayload(array $messages, string $model, array $tools = []): array
    {
        $systemPrompt = $this->extractSystemPrompt($messages);
        $formattedMessages = $this->formatMessages($messages);

        $payload = [
            'model' => $model,
            'max_tokens' => 8192,
            'stream' => true,
            'messages' => $formattedMessages,
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        if (! empty($tools)) {
            $payload['tools'] = $this->formatTools($tools);
        }

        return $payload;
    }

    protected function extractSystemPrompt(array $messages): ?string
    {
        foreach ($messages as $msg) {
            if (($msg['role'] ?? '') === 'system') {
                return $msg['content'] ?? null;
            }
        }

        return null;
    }

    protected function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];

            // Skip system messages (handled separately)
            if ($role === 'system') {
                continue;
            }

            // Map roles
            $anthropicRole = match ($role) {
                'assistant' => 'assistant',
                'tool' => 'user', // Tool results go in user messages
                default => 'user',
            };

            $content = [];

            // Regular text content
            if (isset($msg['content']) && $msg['content'] !== '') {
                if ($role === 'tool') {
                    $content[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $msg['tool_call_id'] ?? '',
                        'content' => $msg['content'],
                    ];
                } else {
                    $content[] = [
                        'type' => 'text',
                        'text' => $msg['content'],
                    ];
                }
            }

            // Handle tool calls from assistant
            if (isset($msg['tool_calls'])) {
                foreach ($msg['tool_calls'] as $toolCall) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $toolCall['id'],
                        'name' => $toolCall['function']['name'],
                        'input' => json_decode($toolCall['function']['arguments'], true) ?? [],
                    ];
                }
            }

            if (! empty($content)) {
                $formatted[] = [
                    'role' => $anthropicRole,
                    'content' => $content,
                ];
            }
        }

        return $formatted;
    }

    protected function formatTools(array $tools): array
    {
        $formatted = [];

        foreach ($tools as $tool) {
            if (($tool['type'] ?? '') === 'function') {
                $formatted[] = [
                    'name' => $tool['function']['name'],
                    'description' => $tool['function']['description'] ?? '',
                    'input_schema' => $tool['function']['parameters'] ?? ['type' => 'object', 'properties' => []],
                ];
            }
        }

        return $formatted;
    }

    protected function processDataLine(string $data, callable $onChunk, callable $onToolCall): void
    {
        $data = trim($data);

        if (empty($data)) {
            return;
        }

        $decoded = json_decode($data, true);

        if (! $decoded) {
            return;
        }

        $type = $decoded['type'] ?? '';

        switch ($type) {
            case 'content_block_delta':
                $delta = $decoded['delta'] ?? [];
                if (($delta['type'] ?? '') === 'text_delta') {
                    $onChunk($delta['text'] ?? '');
                } elseif (($delta['type'] ?? '') === 'input_json_delta') {
                    // Tool input being streamed - handled at content_block_stop
                }
                break;

            case 'content_block_start':
                $contentBlock = $decoded['content_block'] ?? [];
                if (($contentBlock['type'] ?? '') === 'tool_use') {
                    // Store pending tool call
                    $this->pendingToolCall = [
                        'id' => $contentBlock['id'] ?? '',
                        'type' => 'function',
                        'function' => [
                            'name' => $contentBlock['name'] ?? '',
                            'arguments' => '',
                        ],
                    ];
                }
                break;

            case 'content_block_stop':
                if (isset($this->pendingToolCall) && ! empty($this->pendingToolCall['function']['name'])) {
                    $onToolCall($this->pendingToolCall);
                    $this->pendingToolCall = null;
                }
                break;
        }
    }

    protected ?array $pendingToolCall = null;
}
