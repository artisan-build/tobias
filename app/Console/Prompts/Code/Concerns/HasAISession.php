<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Concerns;

use App\Console\Prompts\Code\Providers\ProviderManager;
use App\Console\Prompts\Code\Support\ChatSession;
use App\Console\Prompts\Code\Support\Session;
use App\Console\Prompts\Code\Support\SessionManager;
use App\Support\CrashLogger;

trait HasAISession
{
    protected SessionManager $sessionManager;

    public function initializeSession(?string $sessionId = null): void
    {
        $this->sessionManager = new SessionManager;

        $renderCallback = fn () => $this->requestRender();

        $main = ChatSession::make('Main', 'worktrees/main');
        $main->onRender = $renderCallback;
        $main->initializeAI($sessionId, $this->getToolDefinitions());
        $this->sessionManager->add($main);

        $feat = ChatSession::make('Code Prompt', 'worktrees/feat/code_prompt');
        $feat->onRender = $renderCallback;
        $feat->initializeAI(null, $this->getToolDefinitions());
        $this->sessionManager->add($feat);
    }

    public function getSessionManager(): SessionManager
    {
        return $this->sessionManager;
    }

    public function getSession(): ?Session
    {
        return $this->sessionManager->active()?->session;
    }

    public function getProviderManager(): ?ProviderManager
    {
        return $this->sessionManager->active()?->providerManager;
    }

    public function isStreaming(): bool
    {
        return $this->sessionManager->active()?->isStreaming ?? false;
    }

    public function getStreamingResponse(): string
    {
        return $this->sessionManager->active()?->response ?? '';
    }

    public function switchModel(string $model): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $provider = match (true) {
            str_starts_with($model, 'gpt') => 'openai',
            str_starts_with($model, 'claude') => 'anthropic',
            str_starts_with($model, 'gemini') => 'gemini',
            str_starts_with($model, 'google') => 'lmstudio',
            default => null,
        };

        if ($provider) {
            $chat->providerManager?->use($provider, $model);
            $this->toastSuccess("Switched to {$model}");
        } else {
            $this->toastError("Unknown model: {$model}");
        }
    }

    public function useProvider(string $provider, ?string $model = null): static
    {
        $this->sessionManager->active()?->providerManager?->use($provider, $model);

        return $this;
    }

    public function saveSession(): bool
    {
        return $this->sessionManager->active()?->session?->save() ?? false;
    }

    public function resumeSession(string $sessionId): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $session = Session::resume($sessionId);

        if ($session) {
            $chat->session = $session;
            $this->toastSuccess('Session resumed');

            $lastMessage = $session->getLastAssistantMessage();

            if ($lastMessage) {
                $chat->response = $lastMessage['content'] ?? '';
            }

            $this->render();
        } else {
            $this->toastError('Failed to resume session');
        }
    }

    public function clearConversation(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $chat->session?->clear();
        $chat->response = '';
        $this->render();
    }

    public function enablePusherIntegration(string $channel): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $chat->systemPrompt?->withPusherIntegration($channel);

        if ($chat->session) {
            $messages = $chat->session->getMessages();
            $messages[0] = $chat->systemPrompt->toMessage();
        }
    }

    public function addPusherMessageToSession(string $type, string $sender, string $message, array $data = []): void
    {
        $content = match ($type) {
            'ai-request' => "[PUSHER REQUEST from {$sender}]: {$message}",
            'action' => "[PUSHER ACTION from {$sender}]: {$message}",
            default => "[PUSHER MESSAGE from {$sender}]: {$message}",
        };

        $this->sessionManager->active()?->session?->addUserMessage($content);
    }

    public function sendMessage(string $message): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            $this->initializeSession();
            $chat = $this->sessionManager->active();
        }

        // Add file contexts as messages, then clear them immediately
        $contextMessages = $this->getFileContextMessages();

        foreach ($contextMessages as $contextMessage) {
            $chat->session->addMessage($contextMessage);
        }

        $this->clearFileContexts();

        $chat->session->addUserMessage($message);
        $chat->response = '';
        $chat->isStreaming = true;
        $chat->status = 'streaming';
        $chat->pendingToolCalls = [];
        $chat->startThinking();
        $this->requestRender();

        $this->streamResponse();
    }

    protected function streamResponse(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        // Filter out tool_summary messages - they're for display only, not for the API
        $messages = $chat->session->getMessages()
            ->filter(fn (array $m) => ($m['role'] ?? '') !== 'tool_summary')
            ->values()
            ->toArray();

        $chat->providerManager->stream(
            messages: $messages,
            onChunk: $this->guardStreamCallback('onChunk', fn (string $chunk) => $this->handleStreamChunk($chunk)),
            onToolCall: $this->guardStreamCallback('onToolCall', fn (array $toolCall) => $this->handleStreamToolCall($toolCall)),
            onComplete: $this->guardStreamCallback('onComplete', fn () => $this->handleStreamComplete()),
            onError: $this->guardStreamCallback('onError', fn (\Throwable $e) => $this->handleStreamError($e)),
            tools: $this->getToolDefinitions(),
        );
    }

    /**
     * Wrap a streaming callback so an exception inside it is logged and shown
     * as a toast rather than escaping the ReactPHP loop and killing the app.
     */
    protected function guardStreamCallback(string $context, callable $callback): callable
    {
        return function (...$args) use ($context, $callback) {
            try {
                return $callback(...$args);
            } catch (\Throwable $e) {
                CrashLogger::exception("stream:{$context}", $e);

                $chat = $this->sessionManager->active();
                if ($chat) {
                    $chat->stopThinking();
                    $chat->isStreaming = false;
                    $chat->status = 'error';
                }

                $this->showToast('Error (logged): '.$e->getMessage());
                $this->requestRender();

                return null;
            }
        };
    }

    protected function handleStreamChunk(string $chunk): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $chat->stopThinking();
        $chat->status = 'streaming';
        $chat->response .= $chunk;
        $chat->scrollOffset = 0;
        $this->requestRender();
    }

    protected function handleStreamToolCall(array $toolCall): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->pendingToolCalls[] = $toolCall;
        }
    }

    protected function handleStreamComplete(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        if (! empty($chat->pendingToolCalls)) {
            $this->executeQueuedToolCalls();

            return;
        }

        $chat->isStreaming = false;
        $chat->status = 'idle';

        if (! empty($chat->response)) {
            $chat->session?->addAssistantMessage($chat->response);
            $this->maybeWhisperResponse();
        }

        $this->requestRender();
    }

    protected function handleStreamError(\Throwable $e): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $chat->stopThinking();
        $chat->isStreaming = false;
        $chat->status = 'error';
        $chat->response = "**Error:** {$e->getMessage()}";
        $chat->session?->addAssistantMessage($chat->response);
        $this->toastError($e->getMessage());
        $this->requestRender();
    }

    protected function executeQueuedToolCalls(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat || empty($chat->pendingToolCalls)) {
            return;
        }

        // Clear any previous tool summaries
        $chat->toolSummaries = [];

        // Add assistant message with tool calls to session
        $chat->session->addMessage([
            'role' => 'assistant',
            'content' => $chat->response ?: null,
            'tool_calls' => array_values($chat->pendingToolCalls),
        ]);

        // Execute each tool and add results
        $results = $this->handleToolCalls(array_values($chat->pendingToolCalls));

        // Check if any tool returned pending_approval (bash approval flow)
        $hasPending = false;
        foreach ($results as $result) {
            if ($result['result'] === 'pending_approval') {
                $hasPending = true;

                continue;
            }

            $chat->session->addToolResult(
                $result['tool_call_id'],
                json_encode($result['result'])
            );
        }

        if ($hasPending) {
            $chat->pendingToolCalls = [];
            $this->requestRender();

            return;
        }

        // Add tool summaries as a system note in the session for display
        if (! empty($chat->toolSummaries)) {
            $chat->session->addMessage([
                'role' => 'tool_summary',
                'content' => implode("\n", $chat->toolSummaries),
            ]);
        }

        $chat->pendingToolCalls = [];
        $chat->response = '';

        // Continue streaming with tool results
        $chat->startThinking();
        $this->streamResponse();
    }

    protected function maybeWhisperResponse(): void
    {
        if (! $this->isPusherConnected()) {
            return;
        }

        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $lastUserMessage = $chat->session?->getMessages()
            ->filter(fn (array $m) => $m['role'] === 'user')
            ->last();

        if ($lastUserMessage && str_starts_with($lastUserMessage['content'] ?? '', '[PUSHER')) {
            $this->whisperToPusher('ai-response', $chat->response, 'response');
        }
    }

    public function addToolSummary(string $summary): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->toolSummaries[] = $summary;
        }
    }

    public function getToolSummaries(): array
    {
        return $this->sessionManager->active()?->toolSummaries ?? [];
    }

    public function clearToolSummaries(): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->toolSummaries = [];
        }
    }

    public function submit(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $message = trim($chat->typedValue);

        if (empty($message)) {
            return;
        }

        $chat->typedValue = '';
        $chat->cursorPosition = 0;
        $this->sendMessage($message);
    }

    /**
     * Request a render on the next frame.
     * Note: This may be overridden by HasRenderLoop via trait conflict resolution.
     */
    protected function requestRender(): void
    {
        $this->render();
    }
}
