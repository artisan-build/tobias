<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Concerns;

use Laravel\Prompts\Key;

trait KeyBindings
{
    private const CTRL_L = "\x0C";

    private const PAGE_UP = "\e[5~";

    private const PAGE_DOWN = "\e[6~";

    private const ALT_ENTER = "\e\r";

    private const ALT_ENTER_ALT = "\e\n";

    private const KITTY_SHIFT_ENTER = "\e[13;2u";

    private const CTRL_UP = "\e[1;5A";

    private const CTRL_DOWN = "\e[1;5B";

    private const MOUSE_SCROLL_UP = 64;

    private const MOUSE_SCROLL_DOWN = 65;

    private const CMD_BACKSPACE = "\e[127;9u";

    private const CTRL_U = "\x15";

    private const CTRL_W = "\x17";

    private const OPT_LEFT = "\e[1;3D";

    private const OPT_RIGHT = "\e[1;3C";

    private const OPT_LEFT_ALT = "\eb";

    private const OPT_RIGHT_ALT = "\ef";

    public function registerKeyBindings(): void
    {
        $this->on('key', fn (string $key) => $this->handleKeyPress($key));
    }

    protected function handleKeyPress(string $key): void
    {
        if ($this->handleMouseEvent($key)) {
            return;
        }

        if ($this->hasActiveModal() || $this->hasActiveFileTree()) {
            $this->handleModalKeyPress($key);

            return;
        }

        match ($this->mode) {
            'command' => $this->handleCommandModeKey($key),
            'insert' => $this->handleInsertModeKey($key),
            default => $this->handleNormalModeKey($key),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // NORMAL MODE
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleNormalModeKey(string $key): void
    {
        match ($key) {
            Key::ENTER, "\r" => $this->handleSubmit(),
            ':' => $this->enterCommandMode(),
            'i' => $this->enterInsertMode(),
            '?' => $this->executeSlashCommand('help'),
            Key::CTRL_P => $this->openCommandPalette(),
            Key::TAB => $this->focusNextPanel(),
            Key::SHIFT_TAB => $this->focusPrevPanel(),
            'q' => $this->executeSlashCommand('quit'),
            ' ' => $this->toggleThinkingAnimation(),
            '~', '@' => $this->openFileTreeForReference(),
            Key::CTRL_C => $this->handleInterrupt(),
            self::CTRL_L => $this->handleClearScreen(),
            self::PAGE_UP, self::CTRL_UP => $this->scrollUp(10),
            self::PAGE_DOWN, self::CTRL_DOWN => $this->scrollDown(10),
            default => $this->handlePanelSpecificKey($key),
        };

        $this->render();
    }

    protected function handlePanelSpecificKey(string $key): void
    {
        match ($this->focusedPanel) {
            'sessions' => $this->handleSessionsPanelKey($key),
            'tools' => $this->handleToolsPanelKey($key),
            'conversation' => $this->handleConversationPanelKey($key),
            'context' => $this->handleContextPanelKey($key),
            default => null,
        };
    }

    protected function handleSessionsPanelKey(string $key): void
    {
        match ($key) {
            'j', Key::DOWN, Key::DOWN_ARROW => $this->sessionManager->cursorDown(),
            'k', Key::UP, Key::UP_ARROW => $this->sessionManager->cursorUp(),
            Key::ENTER, "\r" => $this->sessionManager->toggleExpandedAtCursor(),
            'l' => $this->sessionManager->selectAtCursor(),
            'n' => $this->createNewSession(),
            'd' => $this->deleteSessionAtCursor(),
            default => null,
        };
    }

    protected function handleToolsPanelKey(string $key): void
    {
        match ($key) {
            'j', Key::DOWN, Key::DOWN_ARROW => $this->scrollUp(1),
            'k', Key::UP, Key::UP_ARROW => $this->scrollDown(1),
            default => null,
        };
    }

    protected function handleConversationPanelKey(string $key): void
    {
        match ($key) {
            'j', Key::DOWN, Key::DOWN_ARROW => $this->scrollUp(1),
            'k', Key::UP, Key::UP_ARROW => $this->scrollDown(1),
            'G' => $this->scrollToBottom(),
            default => null,
        };
    }

    protected function handleContextPanelKey(string $key): void
    {
        match ($key) {
            'a' => $this->openFileTreeForReference(),
            default => null,
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INSERT MODE — delegates editing to TextArea component
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleInsertModeKey(string $key): void
    {
        match ($key) {
            Key::ESCAPE => $this->enterNormalMode(),
            Key::ENTER, "\r" => $this->handleNewline(),
            Key::CTRL_C => $this->handleInterrupt(),
            Key::UP, Key::UP_ARROW => $this->delegateToTextArea('moveUp'),
            Key::DOWN, Key::DOWN_ARROW => $this->delegateToTextArea('moveDown'),
            Key::LEFT, Key::LEFT_ARROW => $this->delegateToTextArea('moveLeft'),
            Key::RIGHT, Key::RIGHT_ARROW => $this->delegateToTextArea('moveRight'),
            Key::BACKSPACE, Key::CTRL_H => $this->delegateToTextArea('backspace'),
            Key::DELETE => $this->delegateToTextArea('delete'),
            self::CTRL_L => $this->handleClearScreen(),
            self::CMD_BACKSPACE, self::CTRL_U => $this->delegateToTextArea('clearLine'),
            self::CTRL_W => $this->delegateToTextArea('deleteWordBackward'),
            self::OPT_LEFT, self::OPT_LEFT_ALT => $this->delegateToTextArea('moveWordLeft'),
            self::OPT_RIGHT, self::OPT_RIGHT_ALT => $this->delegateToTextArea('moveWordRight'),
            self::PAGE_UP, self::CTRL_UP => $this->scrollUp(10),
            self::PAGE_DOWN, self::CTRL_DOWN => $this->scrollDown(10),
            Key::TAB => $this->handleInsertTab(),
            default => $this->handleCharacterInput($key),
        };

        $this->render();
    }

    protected function delegateToTextArea(string $method): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $this->inputTextArea->value = $chat->typedValue;
        $this->inputTextArea->cursorPosition = $chat->cursorPosition;
        $this->inputTextArea->{$method}();
        $this->syncFromTextArea();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMMAND MODE
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleCommandModeKey(string $key): void
    {
        match ($key) {
            Key::ENTER => $this->executeColonCommand(),
            Key::ESCAPE => $this->enterNormalMode(),
            Key::BACKSPACE, Key::CTRL_H => $this->commandBackspace(),
            Key::TAB => $this->completeColonCommand(),
            default => $this->appendToCommandBuffer($key),
        };

        $this->render();
    }

    protected function executeColonCommand(): void
    {
        $command = trim($this->commandBuffer);
        $this->commandBuffer = '';
        $this->mode = 'normal';

        if (empty($command)) {
            return;
        }

        if (str_starts_with($command, 'model ')) {
            $model = trim(substr($command, 6));
            if (! empty($model)) {
                $this->switchModel($model);
            }

            return;
        }

        $this->executeSlashCommand($command);
    }

    protected function commandBackspace(): void
    {
        if ($this->commandBuffer !== '') {
            $this->commandBuffer = mb_substr($this->commandBuffer, 0, -1);
        } else {
            $this->enterNormalMode();
        }
    }

    protected function appendToCommandBuffer(string $key): void
    {
        if (strlen($key) === 1 && ctype_print($key)) {
            $this->commandBuffer .= $key;
        }
    }

    protected function completeColonCommand(): void
    {
        $query = $this->commandBuffer;
        $matches = $this->getMatchingCommands($query);

        if ($matches->count() === 1) {
            $this->commandBuffer = $matches->first()['signature'];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODE TRANSITIONS
    // ─────────────────────────────────────────────────────────────────────────

    protected function enterNormalMode(): void
    {
        $this->mode = 'normal';
        $this->commandBuffer = '';
    }

    protected function enterInsertMode(): void
    {
        $this->mode = 'insert';
        $this->focusedPanel = 'conversation';
    }

    protected function enterCommandMode(): void
    {
        $this->mode = 'command';
        $this->commandBuffer = '';
    }

    protected function openCommandPalette(): void
    {
        $commands = $this->getCommands()
            ->map(fn (array $cmd) => [
                'label' => $cmd['signature'].' - '.$cmd['description'],
                'value' => $cmd['signature'],
            ])
            ->values()
            ->all();

        $this->openModal('command_palette', [
            'commands' => $commands,
            'selectedIndex' => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODAL KEY HANDLING
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleModalKeyPress(string $key): void
    {
        if ($this->activeModalType === 'bash_approval') {
            $this->handleBashApprovalKey($key);

            return;
        }

        if ($this->hasActiveFileTree()) {
            $this->handleFileTreeKeyPress($key);

            return;
        }

        match ($this->activeModalType) {
            'help' => $this->handleHelpModalKey($key),
            'resume' => $this->handleResumeModalKey($key),
            'context' => $this->handleContextModalKey($key),
            'command_palette' => $this->handleCommandPaletteModalKey($key),
            default => $this->handleGenericModalKey($key),
        };

        $this->render();
    }

    protected function handleHelpModalKey(string $key): void
    {
        if ($key === Key::ESCAPE || $key === '?' || $key === 'q') {
            $this->closeModal();
        }
    }

    protected function handleResumeModalKey(string $key): void
    {
        $sessions = $this->modalState['sessions'] ?? [];
        $maxIndex = max(0, count($sessions) - 1);

        match ($key) {
            'j', Key::DOWN, Key::DOWN_ARROW => $this->modalState['selectedIndex'] = min($maxIndex, ($this->modalState['selectedIndex'] ?? 0) + 1),
            'k', Key::UP, Key::UP_ARROW => $this->modalState['selectedIndex'] = max(0, ($this->modalState['selectedIndex'] ?? 0) - 1),
            Key::ENTER => $this->selectResumeSession(),
            Key::ESCAPE => $this->closeModal(),
            default => null,
        };
    }

    protected function selectResumeSession(): void
    {
        $sessions = $this->modalState['sessions'] ?? [];
        $selectedIndex = $this->modalState['selectedIndex'] ?? 0;
        $selected = $sessions[$selectedIndex] ?? null;

        if ($selected) {
            $sessionId = $selected['id'];
            $this->closeModal();
            $this->resumeSession($sessionId);
        }
    }

    protected function handleContextModalKey(string $key): void
    {
        $options = $this->modalState['options'] ?? [];
        $maxIndex = max(0, count($options) - 1);

        match ($key) {
            'j', Key::DOWN, Key::DOWN_ARROW => $this->modalState['selectedIndex'] = min($maxIndex, ($this->modalState['selectedIndex'] ?? 0) + 1),
            'k', Key::UP, Key::UP_ARROW => $this->modalState['selectedIndex'] = max(0, ($this->modalState['selectedIndex'] ?? 0) - 1),
            Key::ENTER => $this->selectContextOption(),
            Key::ESCAPE => $this->closeModal(),
            default => null,
        };
    }

    protected function selectContextOption(): void
    {
        $options = $this->modalState['options'] ?? [];
        $selectedIndex = $this->modalState['selectedIndex'] ?? 0;
        $selected = $options[$selectedIndex] ?? null;

        if (! $selected) {
            return;
        }

        $value = $selected['value'];
        $this->closeModal();

        match ($value) {
            'add' => $this->showFileTree(),
            'clear' => $this->clearFileContexts(),
            default => $this->removeFileContext((int) $value),
        };
    }

    protected function handleCommandPaletteModalKey(string $key): void
    {
        $commands = $this->modalState['commands'] ?? [];
        $maxIndex = max(0, count($commands) - 1);

        match ($key) {
            'j', Key::DOWN, Key::DOWN_ARROW => $this->modalState['selectedIndex'] = min($maxIndex, ($this->modalState['selectedIndex'] ?? 0) + 1),
            'k', Key::UP, Key::UP_ARROW => $this->modalState['selectedIndex'] = max(0, ($this->modalState['selectedIndex'] ?? 0) - 1),
            Key::ENTER => $this->selectCommandPaletteOption(),
            Key::ESCAPE => $this->closeModal(),
            default => null,
        };
    }

    protected function selectCommandPaletteOption(): void
    {
        $commands = $this->modalState['commands'] ?? [];
        $selectedIndex = $this->modalState['selectedIndex'] ?? 0;
        $selected = $commands[$selectedIndex] ?? null;

        if ($selected) {
            $this->closeModal();
            $this->executeSlashCommand($selected['value']);
        }
    }

    protected function handleBashApprovalKey(string $key): void
    {
        match ($key) {
            'y', Key::ENTER => $this->approveBashCommand(),
            'n', Key::ESCAPE => $this->denyBashCommand(),
            default => null,
        };

        $this->render();
    }

    protected function handleGenericModalKey(string $key): void
    {
        if ($key === Key::ESCAPE) {
            $this->closeModal();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FILE TREE KEY HANDLING
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleFileTreeKeyPress(string $key): void
    {
        $fileTree = $this->getFileTreeComponent();

        if (! $fileTree) {
            return;
        }

        match ($key) {
            Key::UP, Key::UP_ARROW, 'k' => $fileTree->moveUp(),
            Key::DOWN, Key::DOWN_ARROW, 'j' => $fileTree->moveDown(),
            Key::ENTER => $fileTree->enter(),
            Key::ESCAPE => $this->hideFileTree(),
            Key::BACKSPACE => $fileTree->backspaceSearch(),
            default => $this->handleFileTreeCharacterInput($key),
        };

        $this->render();
    }

    protected function handleFileTreeCharacterInput(string $key): void
    {
        if (strlen($key) === 1 && ctype_print($key)) {
            $this->getFileTreeComponent()?->appendToSearch($key);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INSERT MODE HANDLERS
    // ─────────────────────────────────────────────────────────────────────────

    protected function handleSubmit(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $input = trim($chat->typedValue);

        if (empty($input)) {
            return;
        }

        if (str_starts_with($input, '/')) {
            $command = substr($input, 1);
            $chat->typedValue = '';
            $chat->cursorPosition = 0;
            $this->executeSlashCommand($command);

            return;
        }

        $input = $this->parseAndAddFileReferences($input);

        if (! empty($input)) {
            $this->submit();
        }
    }

    protected function handleInsertTab(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $this->inputTextArea->value = $chat->typedValue;
        $this->inputTextArea->cursorPosition = $chat->cursorPosition;
        $this->inputTextArea->insert("\t");
        $this->syncFromTextArea();
    }

    protected function handleInterrupt(): void
    {
        $this->terminate();
    }

    protected function handleClearScreen(): void
    {
        echo "\e[2J\e[H";
        $this->render();
    }

    protected function handleNewline(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $this->inputTextArea->value = $chat->typedValue;
        $this->inputTextArea->cursorPosition = $chat->cursorPosition;
        $this->inputTextArea->insertNewline();
        $this->syncFromTextArea();
        $this->render();
    }

    protected function handleCharacterInput(string $key): void
    {
        if ($key === '~' || $key === '@') {
            $this->openFileTreeForReference();
            $this->render();

            return;
        }

        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        if ($key === '/' && empty(trim($chat->typedValue))) {
            $this->showSlashCommandModal('');

            return;
        }

        // Insert printable characters via TextArea (exclude DEL/control chars)
        $ord = mb_strlen($key) === 1 ? mb_ord($key) : -1;
        if ($ord >= 32 && $ord !== 127) {
            $this->inputTextArea->value = $chat->typedValue;
            $this->inputTextArea->cursorPosition = $chat->cursorPosition;
            $this->inputTextArea->insert($key);
            $this->syncFromTextArea();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MOUSE HANDLING
    // ─────────────────────────────────────────────────────────────────────────

    public function enableMouseTracking(): void
    {
        fwrite(STDOUT, "\e[?1002h\e[?1006h");
        fflush(STDOUT);
    }

    public function disableMouseTracking(): void
    {
        fwrite(STDOUT, "\e[?1006l\e[?1002l");
        fflush(STDOUT);
    }

    protected function handleMouseEvent(string $input): bool
    {
        if (preg_match('/\e\[<(\d+);(\d+);(\d+)([Mm])/', $input, $matches)) {
            $button = (int) $matches[1];

            if ($button === self::MOUSE_SCROLL_UP) {
                $this->scrollUp(1);

                return true;
            }

            if ($button === self::MOUSE_SCROLL_DOWN) {
                $this->scrollDown(1);

                return true;
            }
        }

        return false;
    }
}
