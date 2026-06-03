<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class Session
{
    protected Collection $messages;

    protected ?string $id = null;

    // protected string $model = 'gpt-5-mini';
    protected string $model = 'google/gemma-4-e4b';

    protected float $temperature = 0.7;

    protected ?int $maxTokens = null;

    protected array $tools = [];

    public function __construct(?string $id = null)
    {
        $this->id = $id ?? Str::uuid()->toString();
        $this->messages = collect();
    }

    public static function make(?string $id = null): static
    {
        return new self($id);
    }

    public static function resume(string $id): ?static
    {
        $path = static::getStoragePath($id);

        if (! file_exists($path)) {
            return null;
        }

        $data = json_decode(file_get_contents($path), true);

        if (! $data) {
            return null;
        }

        $session = new static($id);
        $session->messages = collect($data['messages'] ?? []);
        $session->model = $data['model'] ?? 'gpt-5-mini';
        $session->temperature = $data['temperature'] ?? 0.7;
        $session->maxTokens = $data['maxTokens'] ?? null;
        $session->tools = $data['tools'] ?? [];

        return $session;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function model(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function temperature(float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function maxTokens(?int $maxTokens): static
    {
        $this->maxTokens = $maxTokens;

        return $this;
    }

    public function tools(array $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    public function addSystemMessage(string $content): static
    {
        $this->messages->push([
            'role' => 'system',
            'content' => $content,
        ]);

        return $this;
    }

    public function addUserMessage(string $content): static
    {
        $this->messages->push([
            'role' => 'user',
            'content' => $content,
        ]);

        return $this;
    }

    public function addAssistantMessage(string $content): static
    {
        $this->messages->push([
            'role' => 'assistant',
            'content' => $content,
        ]);

        return $this;
    }

    public function addToolResult(string $toolCallId, string $content): static
    {
        $this->messages->push([
            'role' => 'tool',
            'tool_call_id' => $toolCallId,
            'content' => $content,
        ]);

        return $this;
    }

    public function addMessage(array $message): static
    {
        $this->messages->push($message);

        return $this;
    }

    public function addMessages(array $messages): static
    {
        foreach ($messages as $message) {
            $this->messages->push($message);
        }

        return $this;
    }

    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function getLastMessage(): ?array
    {
        return $this->messages->last();
    }

    public function getLastAssistantMessage(): ?array
    {
        return $this->messages
            ->filter(fn (array $m) => $m['role'] === 'assistant')
            ->last();
    }

    public function clear(): static
    {
        $systemMessages = $this->messages->filter(fn (array $m) => $m['role'] === 'system');
        $this->messages = $systemMessages->values();

        return $this;
    }

    public function toRequestPayload(): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $this->messages->all(),
            'temperature' => $this->temperature,
            'stream' => true,
        ];

        if ($this->maxTokens !== null) {
            $payload['max_tokens'] = $this->maxTokens;
        }

        if (! empty($this->tools)) {
            $payload['tools'] = $this->tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    public function save(): bool
    {
        $path = static::getStoragePath($this->id);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = [
            'id' => $this->id,
            'model' => $this->model,
            'temperature' => $this->temperature,
            'maxTokens' => $this->maxTokens,
            'tools' => $this->tools,
            'messages' => $this->messages->all(),
            'savedAt' => now()->toIso8601String(),
        ];

        return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT)) !== false;
    }

    public function estimateTokenUsage(): int
    {
        $chars = $this->messages->sum(function (array $m) {
            $count = mb_strlen($m['content'] ?? '');
            if (isset($m['tool_calls'])) {
                $count += mb_strlen(json_encode($m['tool_calls']));
            }

            return $count;
        });

        // ~4 chars per token is a reasonable estimate
        return (int) ceil($chars / 4);
    }

    public static function list(): Collection
    {
        $dir = static::getStorageDir();

        if (! is_dir($dir)) {
            return collect();
        }

        return collect(scandir($dir))
            ->filter(fn (string $file) => Str::endsWith($file, '.json'))
            ->map(function (string $file) use ($dir) {
                $data = json_decode(file_get_contents("{$dir}/{$file}"), true);

                return [
                    'id' => $data['id'] ?? Str::before($file, '.json'),
                    'savedAt' => $data['savedAt'] ?? null,
                    'messageCount' => count($data['messages'] ?? []),
                    'model' => $data['model'] ?? 'unknown',
                ];
            })
            ->sortByDesc('savedAt')
            ->values();
    }

    public static function delete(string $id): bool
    {
        $path = static::getStoragePath($id);

        if (file_exists($path)) {
            return unlink($path);
        }

        return false;
    }

    protected static function getStorageDir(): string
    {
        return storage_path('app/code-sessions');
    }

    protected static function getStoragePath(string $id): string
    {
        return static::getStorageDir()."/{$id}.json";
    }
}
