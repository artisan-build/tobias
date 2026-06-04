<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code;

use App\Console\Prompts\Code\Concerns as CodeConcerns;
use App\Console\Prompts\Code\Support\ChatSession;
use App\Support\CrashLogger;
use App\Support\Prompts\AsyncPrompt;
use ArtisanBuild\Parfait\Components\TextArea;
use ArtisanBuild\Parfait\Enums\Color;
use ArtisanBuild\Parfait\Support\Container;
use Laravel\Prompts\Concerns;
use Throwable;

class CodePrompt extends AsyncPrompt
{
    use CodeConcerns\FileReferences;
    use CodeConcerns\HasAISession;
    use CodeConcerns\HasRenderLoop {
        CodeConcerns\HasRenderLoop::requestRender insteadof CodeConcerns\HasAISession;
    }
    use CodeConcerns\HasUILayers;
    use CodeConcerns\KeyBindings;
    use CodeConcerns\SlashCommands;
    use CodeConcerns\Tools;
    use Concerns\Truncation;

    protected ?string $pusherChannel = null;

    protected bool $inAltScreen = false;

    protected bool $renderGuardActive = false;

    public int $terminalWidth = 80;

    public int $terminalHeight = 24;

    // Vim mode state (global UI, not per-session)
    protected string $mode = 'insert';

    protected string $focusedPanel = 'conversation';

    protected string $commandBuffer = '';

    protected ?string $activeModalType = null;

    protected array $modalState = [];

    protected ?string $toastMessage = null;

    protected float $toastStartTime = 0;

    protected float $toastDuration = 2.5;

    protected float $toastSlideTime = 0.3;

    public TextArea $inputTextArea;

    public function __construct(
        public string $prompt = '',
        public string|bool $required = false,
        ?string $sessionId = null,
        ?string $pusherChannel = null,
    ) {
        $this->validate = null;
        static::$themes['default'][static::class] = CodePromptRenderer::class;

        $this->installErrorHandling();

        $this->inputTextArea = TextArea::make('input')
            ->placeholder('Type here... Esc then Enter to send')
            ->fg(Color::White)
            ->cursorColor(Color::Cyan);

        $this->enterAltScreen();
        $this->enableMouseTracking();

        $this->registerCleanupHandlers();

        try {
            $this->initializeFileReferences();
            $this->registerSlashCommands();
            $this->registerTools();
            $this->initializeSession($sessionId);
            $this->initializeRenderLoop();

            $this->registerKeyBindings();

            if ($pusherChannel) {
                $this->connectToPusher($pusherChannel);
            }

            if (! empty($this->prompt)) {
                $this->sendMessage($this->prompt);
            }
        } catch (Throwable $e) {
            $this->disableMouseTracking();
            $this->exitAltScreen();
            throw $e;
        }
    }

    protected function enterAltScreen(): void
    {
        if (getenv('NO_ALT_SCREEN')) {
            return;
        }

        static::output()->write("\e[?1049h");
        static::output()->write("\e]0;Tobias\007");
        $this->inAltScreen = true;
    }

    public function exitAltScreen(): void
    {
        if (! $this->inAltScreen) {
            return;
        }

        $this->disableMouseTracking();
        $this->showCursor();
        static::output()->write("\e[?1049l");
        $this->inAltScreen = false;
    }

    public function hideCursor(): void
    {
        static::output()->write("\e[?25l");
    }

    public function showCursor(): void
    {
        static::output()->write("\e[?25h");
    }

    /**
     * Keep non-fatal PHP errors from killing the event loop.
     *
     * laravel-zero routes deprecations through a NullLogger, so a single
     * "implicit float to int" notice becomes a fatal Error that escapes the
     * ReactPHP loop and exits the process with no visible message (the TUI is
     * in the alternate screen). We log such errors and swallow them instead,
     * and record genuine fatals on shutdown with the terminal restored.
     */
    protected function installErrorHandling(): void
    {
        set_error_handler(function (int $errno, string $message, string $file = '', int $line = 0): bool {
            if (! (error_reporting() & $errno)) {
                return true; // suppressed with @ — ignore
            }

            $label = match ($errno) {
                E_DEPRECATED, E_USER_DEPRECATED => 'DEPRECATED',
                E_NOTICE, E_USER_NOTICE => 'NOTICE',
                E_WARNING, E_USER_WARNING => 'WARNING',
                default => 'PHP',
            };

            CrashLogger::message($label, sprintf('%s in %s:%d', $message, $file, $line));

            return true; // handled — do not propagate to the framework handler
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null || ! in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            CrashLogger::message('FATAL', sprintf('%s in %s:%d', $error['message'], $error['file'], $error['line']));
            $this->cleanup();
            fwrite(STDERR, "\nToby crashed. Details were logged to ".CrashLogger::path()."\n");
        });
    }

    /**
     * Render, but never let a rendering bug take down the session. A failed
     * frame is logged and surfaced as a toast on the next good frame.
     */
    protected function render(): void
    {
        if ($this->renderGuardActive) {
            return; // never recurse through a failing render
        }

        try {
            parent::render();
        } catch (Throwable $e) {
            $this->renderGuardActive = true;
            CrashLogger::exception('render', $e);
            $this->showToast('Render error (logged): '.$e->getMessage());
            $this->renderGuardActive = false;
        }
    }

    protected function registerCleanupHandlers(): void
    {
        register_shutdown_function(function () {
            $this->cleanup();
        });

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, function () {
                $this->cleanup();
                exit(0);
            });
            pcntl_signal(SIGTERM, function () {
                $this->cleanup();
                exit(0);
            });
        }
    }

    protected function cleanup(): void
    {
        fwrite(STDOUT, "\e[?1006l\e[?1002l");
        fwrite(STDOUT, "\e[?25h");

        if ($this->inAltScreen) {
            fwrite(STDOUT, "\e[?1049l");
            $this->inAltScreen = false;
        }

        fflush(STDOUT);
    }

    public function __destruct()
    {
        $this->exitAltScreen();
    }

    public function connectToPusher(string $channel): static
    {
        $this->pusherChannel = $channel;
        $this->connectPusher($channel);
        $this->enablePusherIntegration($channel);

        return $this;
    }

    public function width(): int
    {
        return $this->terminal()->cols();
    }

    public function height(): int
    {
        return $this->terminal()->lines();
    }

    public function value(): string
    {
        return $this->getStreamingResponse();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELEGATION TO ACTIVE CHAT SESSION
    // ─────────────────────────────────────────────────────────────────────────

    public function getTypedValue(): string
    {
        return $this->sessionManager->active()?->typedValue ?? '';
    }

    public function getCursorPosition(): int
    {
        return $this->sessionManager->active()?->cursorPosition ?? 0;
    }

    public function getScrollOffset(): int
    {
        return $this->sessionManager->active()?->scrollOffset ?? 0;
    }

    public function getToolCallHistory(): array
    {
        return $this->sessionManager->active()?->toolCallHistory ?? [];
    }

    public function getConversationForDisplay(): array
    {
        return $this->sessionManager->active()?->getConversationForDisplay() ?? [];
    }

    public function isThinking(): bool
    {
        return $this->sessionManager->active()?->thinkingTimer !== null;
    }

    public function getThinkingFrame(): int
    {
        return $this->sessionManager->active()?->thinkingFrame ?? 0;
    }

    public function getThinkingElapsed(): float
    {
        $chat = $this->sessionManager->active();
        if (! $chat || $chat->thinkingStartTime <= 0) {
            return 0.0;
        }

        return microtime(true) - $chat->thinkingStartTime;
    }

    public function hasPendingBashApproval(): bool
    {
        return $this->sessionManager->active()?->pendingBashApproval !== null;
    }

    public function getPendingBashApproval(): ?array
    {
        return $this->sessionManager->active()?->pendingBashApproval;
    }

    /**
     * Sync the TextArea component with TypedValue state.
     * Called before rendering so the component reflects current input.
     */
    public function syncTextArea(int $width): void
    {
        $chat = $this->sessionManager->active();
        $this->inputTextArea->value = $chat?->typedValue ?? '';
        $this->inputTextArea->cursorPosition = $chat?->cursorPosition ?? 0;
        $this->inputTextArea->setContainer(new Container($width, 1));
    }

    /**
     * Pull TextArea state back into TypedValue after edits.
     */
    protected function syncFromTextArea(): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->typedValue = $this->inputTextArea->value;
            $chat->cursorPosition = $this->inputTextArea->cursorPosition;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SCROLL
    // ─────────────────────────────────────────────────────────────────────────

    public function scrollUp(int $lines = 3): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->scrollOffset = max(0, $chat->scrollOffset + $lines);
        }
        $this->requestRender();
    }

    public function scrollDown(int $lines = 3): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->scrollOffset = max(0, $chat->scrollOffset - $lines);
        }
        $this->requestRender();
    }

    public function scrollToBottom(): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->scrollOffset = 0;
        }
        $this->render();
    }

    public function addToolCallToHistory(string $name, array $arguments, mixed $result): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->toolCallHistory[] = [
                'name' => $name,
                'arguments' => $arguments,
                'result' => $result,
                'time' => microtime(true),
            ];
        }
    }

    public function getPusherChannel(): ?string
    {
        return $this->pusherChannel;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VIM MODE
    // ─────────────────────────────────────────────────────────────────────────

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function getFocusedPanel(): string
    {
        return $this->focusedPanel;
    }

    public function getCommandBuffer(): string
    {
        return $this->commandBuffer;
    }

    public function getActiveModalType(): ?string
    {
        return $this->activeModalType;
    }

    public function getModalState(): array
    {
        return $this->modalState;
    }

    public function getToastMessage(): ?string
    {
        return $this->toastMessage;
    }

    public function getToastProgress(): float
    {
        if ($this->toastMessage === null) {
            return 0;
        }

        $elapsed = microtime(true) - $this->toastStartTime;

        if ($elapsed < $this->toastSlideTime) {
            $t = $elapsed / $this->toastSlideTime;

            return $t * $t * (3 - 2 * $t);
        }

        if ($elapsed < $this->toastDuration) {
            return 1.0;
        }

        $slideOutProgress = ($elapsed - $this->toastDuration) / $this->toastSlideTime;
        $t = min(1.0, $slideOutProgress);

        return 1.0 + $t * $t * (3 - 2 * $t);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PANEL NAVIGATION
    // ─────────────────────────────────────────────────────────────────────────

    public function focusNextPanel(): void
    {
        $panels = ['sessions', 'conversation', 'context', 'tools'];
        $index = array_search($this->focusedPanel, $panels);
        $this->focusedPanel = $panels[($index + 1) % count($panels)];
    }

    public function focusPrevPanel(): void
    {
        $panels = ['sessions', 'conversation', 'context', 'tools'];
        $index = array_search($this->focusedPanel, $panels);
        $this->focusedPanel = $panels[($index - 1 + count($panels)) % count($panels)];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // THINKING ANIMATION
    // ─────────────────────────────────────────────────────────────────────────

    public function startThinkingAnimation(): void
    {
        $this->sessionManager->active()?->startThinking();
    }

    public function stopThinkingAnimation(): void
    {
        $this->sessionManager->active()?->stopThinking();
    }

    public function toggleThinkingAnimation(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        if ($chat->thinkingTimer !== null) {
            $chat->stopThinking();
        } else {
            $chat->startThinking();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOAST
    // ─────────────────────────────────────────────────────────────────────────

    public function showToast(string $message): void
    {
        $this->toastMessage = $message;
        $this->toastStartTime = microtime(true);
    }

    public function clearExpiredToast(): void
    {
        if ($this->toastMessage !== null) {
            $elapsed = microtime(true) - $this->toastStartTime;
            if ($elapsed > $this->toastDuration + $this->toastSlideTime) {
                $this->toastMessage = null;
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODAL
    // ─────────────────────────────────────────────────────────────────────────

    public function openModal(string $type, array $state = []): void
    {
        $this->saveTypedValue();
        $this->activeModalType = $type;
        $this->modalState = $state;
    }

    public function closeModal(): void
    {
        $this->activeModalType = null;
        $this->modalState = [];
        $this->restoreTypedValue();
        $this->clearSavedTypedValue();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // BASH APPROVAL
    // ─────────────────────────────────────────────────────────────────────────

    public function requestBashApproval(array $toolCall, string $command, ?int $timeout, ?string $workingDirectory): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->pendingBashApproval = [
                'tool_call' => $toolCall,
                'command' => $command,
                'timeout' => $timeout,
                'working_directory' => $workingDirectory,
            ];
        }

        $this->openModal('bash_approval', [
            'command' => $command,
            'working_directory' => $workingDirectory ?? getcwd(),
        ]);
    }

    public function approveBashCommand(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat || ! $chat->pendingBashApproval) {
            return;
        }

        $pending = $chat->pendingBashApproval;
        $chat->pendingBashApproval = null;
        $this->closeModal();

        $result = $this->executeTool('run_bash', [
            'command' => $pending['command'],
            'timeout' => $pending['timeout'],
            'working_directory' => $pending['working_directory'],
        ]);

        $this->completePendingToolCall($pending['tool_call'], $result);
    }

    public function denyBashCommand(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat || ! $chat->pendingBashApproval) {
            return;
        }

        $pending = $chat->pendingBashApproval;
        $chat->pendingBashApproval = null;
        $this->closeModal();

        $result = [
            'success' => false,
            'error' => 'Command denied by user',
            'command' => $pending['command'],
        ];

        $this->completePendingToolCall($pending['tool_call'], $result);
    }

    protected function completePendingToolCall(array $toolCall, mixed $result): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $name = $toolCall['function']['name'] ?? 'run_bash';
        $arguments = json_decode($toolCall['function']['arguments'] ?? '{}', true);

        $this->addToolCallToHistory($name, $arguments, $result);

        $summary = $this->generateToolSummary($name, $arguments, $result);

        if ($summary) {
            $this->addToolSummary($summary);
        }

        $chat->session->addToolResult(
            $toolCall['id'],
            json_encode($result)
        );

        if (! empty($chat->toolSummaries)) {
            $chat->session->addMessage([
                'role' => 'tool_summary',
                'content' => implode("\n", $chat->toolSummaries),
            ]);
        }

        $chat->response = '';
        $chat->startThinking();
        $this->streamResponse();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SESSION MANAGEMENT
    // ─────────────────────────────────────────────────────────────────────────

    public function createNewSession(?string $name = null): void
    {
        static $sessionCounter = 1;
        $sessionCounter++;

        $name = $name ?? "Session {$sessionCounter}";
        $worktree = 'worktrees/'.strtolower(str_replace(' ', '-', $name));

        $chat = ChatSession::make($name, $worktree);
        $chat->onRender = fn () => $this->requestRender();
        $chat->initializeAI(null, $this->getToolDefinitions());
        $this->sessionManager->add($chat);
        $this->sessionManager->setActive($chat->id);

        $this->toastSuccess("Created: {$name}");
    }

    public function deleteSessionAtCursor(): void
    {
        $sessions = $this->sessionManager->all();
        $cursorIndex = $this->sessionManager->getCursorIndex();

        if (count($sessions) <= 1) {
            $this->toastError('Cannot delete last session');

            return;
        }

        if (isset($sessions[$cursorIndex])) {
            $session = $sessions[$cursorIndex];
            $session->stopThinking();
            $this->sessionManager->remove($session->id);
            $this->toastSuccess("Deleted: {$session->name}");
        }
    }

    public function terminate(): void
    {
        // Stop all session thinking timers
        foreach ($this->sessionManager->all() as $chat) {
            $chat->stopThinking();
        }

        $this->stopRenderLoop();
        $this->disconnectPusher();
        $this->disableMouseTracking();
        $this->showCursor();
        $this->exitAltScreen();
        parent::submit();
    }
}
