<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Support;

use App\Console\Prompts\Code\Providers\ProviderManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;

class ChatSession
{
    public string $id;

    public string $name;

    public string $description;

    public string $worktree;

    // AI state
    public ?Session $session = null;

    public ?ProviderManager $providerManager = null;

    public ?SystemPrompt $systemPrompt = null;

    public string $response = '';

    public bool $isStreaming = false;

    public array $pendingToolCalls = [];

    public array $toolSummaries = [];

    // UI state
    public string $status = 'idle'; // idle, thinking, streaming, error

    public int $thinkingFrame = 0;

    public float $thinkingStartTime = 0.0;

    public ?TimerInterface $thinkingTimer = null;

    // Input state
    public string $typedValue = '';

    public int $cursorPosition = 0;

    public int $scrollOffset = 0;

    // Tool history
    public array $toolCallHistory = [];

    // File contexts
    public Collection $fileContexts;

    // Bash approval
    public ?array $pendingBashApproval = null;

    /** @var ?\Closure Callback to request a UI re-render */
    public ?\Closure $onRender = null;

    public function __construct(string $name, string $worktree, ?string $sessionId = null)
    {
        $this->id = Str::uuid()->toString();
        $this->name = $name;
        $this->description = '';
        $this->worktree = $worktree;
        $this->fileContexts = collect();
    }

    public static function make(string $name, ?string $worktree = null): static
    {
        return new static($name, $worktree ?? getcwd());
    }

    public function initializeAI(?string $sessionId = null, array $toolDefinitions = []): void
    {
        $this->providerManager = new ProviderManager;
        // $this->providerManager->use('openai', 'gpt-5.2');
        $this->providerManager->use('lmstudio', 'google/gemma-4-e4b');

        $this->session = $sessionId
            ? Session::resume($sessionId) ?? Session::make()
            : Session::make();

        $this->systemPrompt = SystemPrompt::default();

        if (! empty($toolDefinitions)) {
            $this->systemPrompt->withAvailableTools($toolDefinitions);
        }

        $this->session->addMessage($this->systemPrompt->toMessage());
    }

    public function startThinking(): void
    {
        if ($this->thinkingTimer !== null) {
            return;
        }

        $this->thinkingFrame = 0;
        $this->thinkingStartTime = microtime(true);
        $this->status = 'thinking';

        $this->thinkingTimer = Loop::addPeriodicTimer(1.0 / 12, function () {
            $this->thinkingFrame++;
            if ($this->onRender) {
                ($this->onRender)();
            }
        });
    }

    public function stopThinking(): void
    {
        if ($this->thinkingTimer !== null) {
            Loop::cancelTimer($this->thinkingTimer);
            $this->thinkingTimer = null;
        }
    }

    public function estimateTokens(): int
    {
        return $this->session?->estimateTokenUsage() ?? 0;
    }

    public function getConversationForDisplay(): array
    {
        if (! $this->session) {
            return [];
        }

        $messages = $this->session->getMessages()
            ->filter(fn (array $m) => in_array($m['role'], ['user', 'assistant', 'tool_summary']))
            ->values()
            ->all();

        if ($this->isStreaming) {
            $lastMsg = end($messages);
            if (! $lastMsg || $lastMsg['role'] !== 'assistant' || $lastMsg['content'] !== $this->response) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $this->response,
                    'streaming' => true,
                ];
            }
        }

        return $messages;
    }

    public function toArray(): array
    {
        $messageCount = $this->session?->getMessages()
            ->filter(fn (array $m) => in_array($m['role'], ['user', 'assistant']))
            ->count() ?? 0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'worktree' => $this->worktree,
            'status' => $this->status,
            'messageCount' => $messageCount,
            'tokens' => $this->estimateTokens(),
        ];
    }
}
