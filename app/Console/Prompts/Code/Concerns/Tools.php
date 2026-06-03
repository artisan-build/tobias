<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Concerns;

use App\Console\Prompts\Code\Tools\ListFiles;
use App\Console\Prompts\Code\Tools\ReadFile;
use App\Console\Prompts\Code\Tools\RunBash;
use App\Console\Prompts\Code\Tools\SubscribePusher;
use App\Console\Prompts\Code\Tools\UpdateFile;
use App\Console\Prompts\Code\Tools\WhisperPusher;
use App\Console\Prompts\Code\Tools\WriteFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

trait Tools
{
    protected Collection $tools;

    protected ?SubscribePusher $pusherSubscription = null;

    protected ?WhisperPusher $pusherWhisper = null;

    public function initializeTools(): void
    {
        $this->tools = collect();
    }

    public function registerTools(): void
    {
        $this->initializeTools();

        $this->registerTool('list_files', new ListFiles)
            ->registerTool('read_file', new ReadFile)
            ->registerTool('write_file', new WriteFile)
            ->registerTool('update_file', new UpdateFile)
            ->registerTool('run_bash', new RunBash);
    }

    public function registerTool(string $name, object $handler): static
    {
        $definition = method_exists($handler, 'definition')
            ? $handler::definition()
            : null;

        $this->tools->put($name, [
            'name' => $name,
            'handler' => $handler,
            'definition' => $definition,
        ]);

        return $this;
    }

    public function getTool(string $name): ?array
    {
        return $this->tools->get($name);
    }

    public function getTools(): Collection
    {
        return $this->tools;
    }

    public function getToolDefinitions(): array
    {
        return $this->tools
            ->map(fn (array $tool) => $tool['definition'])
            ->filter()
            ->values()
            ->all();
    }

    public function executeTool(string $name, array $arguments = []): mixed
    {
        $tool = $this->getTool($name);

        if (! $tool) {
            return [
                'success' => false,
                'error' => "Unknown tool: {$name}",
            ];
        }

        $handler = $tool['handler'];

        // Convert snake_case keys to camelCase for PHP method parameters
        $camelCaseArgs = [];
        foreach ($arguments as $key => $value) {
            $camelCaseArgs[Str::camel($key)] = $value;
        }

        // Prefer synchronous handler for immediate results
        if (method_exists($handler, 'handleSync')) {
            return $handler->handleSync(...$camelCaseArgs);
        }

        if (method_exists($handler, 'handle')) {
            return $handler->handle(...$camelCaseArgs);
        }

        return [
            'success' => false,
            'error' => "Tool {$name} does not have a handle method",
        ];
    }

    public function handleToolCall(array $toolCall): mixed
    {
        $name = $toolCall['function']['name'] ?? null;
        $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);

        if (! $name) {
            return [
                'success' => false,
                'error' => 'Tool call missing function name',
            ];
        }

        // For run_bash, request user approval instead of executing immediately
        if ($name === 'run_bash' && method_exists($this, 'requestBashApproval')) {
            $this->requestBashApproval(
                $toolCall,
                $arguments['command'] ?? '',
                $arguments['timeout'] ?? null,
                $arguments['working_directory'] ?? null,
            );

            return 'pending_approval';
        }

        $this->toastInfo("Running: {$name}");

        $result = $this->executeTool($name, $arguments);

        // Track tool call history
        if (method_exists($this, 'addToolCallToHistory')) {
            $this->addToolCallToHistory($name, $arguments, $result);
        }

        // Generate and store tool summary for display
        $summary = $this->generateToolSummary($name, $arguments, $result);
        if ($summary && method_exists($this, 'addToolSummary')) {
            $this->addToolSummary($summary);
        }

        return $result;
    }

    protected function generateToolSummary(string $name, array $arguments, mixed $result): ?string
    {
        $success = $result['success'] ?? false;

        if (! $success) {
            $error = $result['error'] ?? 'Unknown error';

            return "⚠️ {$name} failed: {$error}";
        }

        return match ($name) {
            'list_files' => $this->summarizeListFiles($arguments, $result),
            'read_file' => $this->summarizeReadFile($arguments, $result),
            'write_file' => $this->summarizeWriteFile($arguments, $result),
            'update_file' => $this->summarizeUpdateFile($arguments, $result),
            'run_bash' => $this->summarizeRunBash($arguments, $result),
            default => "✓ {$name} completed",
        };
    }

    protected function summarizeListFiles(array $arguments, array $result): string
    {
        $path = $arguments['path'] ?? '.';
        $count = $result['total_entries'] ?? 0;
        $hidden = ($arguments['show_hidden'] ?? false) ? ' (including hidden)' : '';

        return "📂 Listed {$count} items in {$path}{$hidden}";
    }

    protected function summarizeReadFile(array $arguments, array $result): string
    {
        $path = $arguments['path'] ?? 'unknown';
        $lines = $result['lines'] ?? [];
        $total = $lines['total'] ?? 0;

        if (isset($lines['start'], $lines['end'])) {
            return "📄 Read {$path} (lines {$lines['start']}-{$lines['end']} of {$total})";
        }

        return "📄 Read {$path} ({$total} lines)";
    }

    protected function summarizeWriteFile(array $arguments, array $result): string
    {
        $path = $arguments['path'] ?? 'unknown';
        $bytes = $result['bytes_written'] ?? 0;

        return "✏️ Wrote {$path} ({$bytes} bytes)";
    }

    protected function summarizeUpdateFile(array $arguments, array $result): string
    {
        $path = $arguments['path'] ?? 'unknown';
        $changes = $result['changes_applied'] ?? 1;

        return "✏️ Updated {$path} ({$changes} change".($changes !== 1 ? 's' : '').')';
    }

    protected function summarizeRunBash(array $arguments, array $result): string
    {
        $command = $arguments['command'] ?? 'unknown';
        $short = strlen($command) > 40 ? substr($command, 0, 37).'...' : $command;
        $exitCode = $result['exit_code'] ?? -1;
        $lines = $result['output_lines'] ?? 0;

        return "⚡ `{$short}` (exit {$exitCode}, {$lines} lines)";
    }

    public function handleToolCalls(array $toolCalls): array
    {
        return collect($toolCalls)
            ->map(fn (array $toolCall) => [
                'tool_call_id' => $toolCall['id'],
                'result' => $this->handleToolCall($toolCall),
            ])
            ->all();
    }

    public function connectPusher(string $channel, string $connection = 'reverb'): static
    {
        $this->pusherSubscription = SubscribePusher::make($connection)
            ->onMessage(fn (array $data) => $this->handlePusherMessage($data))
            ->onJoin(fn (array $user) => $this->handlePusherJoin($user))
            ->onLeave(fn (array $user) => $this->handlePusherLeave($user))
            ->onError(fn (\Exception $e) => $this->handlePusherError($e))
            ->subscribe($channel);

        $this->pusherWhisper = new WhisperPusher;

        $this->toastInfo("Connected to channel: {$channel}");

        return $this;
    }

    public function disconnectPusher(): static
    {
        if ($this->pusherSubscription) {
            $this->pusherSubscription->unsubscribe();
            $this->pusherSubscription = null;
            $this->pusherWhisper = null;
        }

        return $this;
    }

    public function whisperToPusher(string $event, string $message, ?string $action = null, array $context = []): ?array
    {
        if (! $this->pusherSubscription?->isConnected() || ! $this->pusherWhisper) {
            return null;
        }

        $channel = $this->pusherSubscription->getChannel();

        if (! $channel) {
            return null;
        }

        return $this->pusherWhisper->handle($channel, $event, $message, $action, $context);
    }

    protected function handlePusherMessage(array $data): void
    {
        $type = $data['type'] ?? 'message';
        $message = $data['message'] ?? $data['text'] ?? '';
        $sender = $data['name'] ?? $data['sender'] ?? 'Remote';

        // Add the message to the AI session
        if (method_exists($this, 'addPusherMessageToSession')) {
            $this->addPusherMessageToSession($type, $sender, $message, $data);
        }

        $this->toastInfo("📨 {$sender}: {$message}");
        $this->render();
    }

    protected function handlePusherJoin(array $user): void
    {
        $name = $user['name'] ?? 'Someone';
        $this->toastInfo("👋 {$name} joined");
        $this->render();
    }

    protected function handlePusherLeave(array $user): void
    {
        $name = $user['name'] ?? 'Someone';
        $this->toastInfo("👋 {$name} left");
        $this->render();
    }

    protected function handlePusherError(\Exception $e): void
    {
        $this->toastError("Pusher error: {$e->getMessage()}");
        $this->render();
    }

    public function isPusherConnected(): bool
    {
        return $this->pusherSubscription?->isConnected() ?? false;
    }
}
