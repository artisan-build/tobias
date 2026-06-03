<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code;

use ArtisanBuild\Parfait\Components\Accordion;
use ArtisanBuild\Parfait\Components\AppHeader;
use ArtisanBuild\Parfait\Components\CommandLine;
use ArtisanBuild\Parfait\Components\Modal;
use ArtisanBuild\Parfait\Components\Panel;
use ArtisanBuild\Parfait\Components\SelectList;
use ArtisanBuild\Parfait\Components\Stack;
use ArtisanBuild\Parfait\Components\StatusBar;
use ArtisanBuild\Parfait\Components\Text;
use ArtisanBuild\Parfait\Components\Toast;
use ArtisanBuild\Parfait\Enums\Color;
use ArtisanBuild\Parfait\Layouts\Layout;
use ArtisanBuild\Parfait\Support\Container;
use ArtisanBuild\Parfait\Support\ScreenBuffer;
use ArtisanBuild\Parfait\Support\StringUtils;
use Laravel\Prompts\Themes\Default\Renderer;

class CodePromptRenderer extends Renderer
{
    public function __invoke(CodePrompt $prompt): string
    {
        $buffer = new ScreenBuffer($prompt->terminalWidth, $prompt->terminalHeight);

        return $buffer
            ->content($this->buildScreen($prompt, $buffer->container()))
            ->modal($this->buildModal($prompt))
            ->toast($this->buildToast($prompt))
            ->render();
    }

    protected function buildScreen(CodePrompt $prompt, Container $container): string
    {
        $width = $container->width;
        $height = $container->height;

        // Layout: header(1) + main content + statusbar(1) + commandline(1) = height
        $contentHeight = $height - 3;

        $sessionsWidth = (int) floor($width * 0.20);
        $rightWidth = (int) floor($width * 0.25);
        $conversationWidth = $width - $sessionsWidth - $rightWidth;

        // Right side: context stacked above tools
        $contextHeight = (int) floor($contentHeight * 0.45);
        $toolsHeight = $contentHeight - $contextHeight;

        $lines = [];

        // 1. App Header
        $lines[] = $this->appHeader($prompt)
            ->setContainer($container->withDimensions($width, 1))
            ->render();

        // 2. Main content area (3 columns: sessions | conversation | right stack)
        $rightColumn = Layout::rows($contextHeight, $toolsHeight)
            ->add($this->contextPanel($prompt, $rightWidth, $contextHeight))
            ->add($this->toolsPanel($prompt, $rightWidth, $toolsHeight));

        $mainContent = Layout::columns($sessionsWidth, $conversationWidth, $rightWidth)
            ->add($this->sessionsPanel($prompt, $sessionsWidth, $contentHeight))
            ->add($this->conversationPanel($prompt, $conversationWidth, $contentHeight))
            ->add($rightColumn)
            ->setContainer($container->withDimensions($width, $contentHeight))
            ->render();

        foreach (explode("\n", $mainContent) as $line) {
            $lines[] = $line;
        }

        // 3. Status bar
        $lines[] = $this->statusBar($prompt)
            ->setContainer($container->withDimensions($width, 1))
            ->render();

        // 4. Command line
        $lines[] = $this->commandLine($prompt)
            ->setContainer($container->withDimensions($width, 1))
            ->render();

        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMPONENT BUILDERS
    // ─────────────────────────────────────────────────────────────────────────

    protected function appHeader(CodePrompt $prompt): AppHeader
    {
        $mode = strtoupper($prompt->getMode());
        $modeColor = match ($prompt->getMode()) {
            'normal' => Color::Green,
            'insert' => Color::Yellow,
            'command' => Color::Magenta,
            default => Color::White,
        };

        $header = AppHeader::make('app-header')
            ->addLeft(' Tobias ', Color::White, bold: true)
            ->bg(Color::Orange);

        // Model badge
        $provider = $prompt->getProviderManager();
        if ($provider) {
            $model = $provider->getModel() ?? 'unknown';
            $header->addLeft(" {$model} ", Color::Gray900, Color::Cyan, bold: true);
        }

        // Active session badge
        $manager = $prompt->getSessionManager();
        $activeChat = $manager->active();
        if ($activeChat) {
            $sessionLabel = $activeChat->name;
            $header->addLeft(" {$sessionLabel} ", Color::Gray900, Color::Green, bold: true);
        }

        // Session count
        $sessionCount = $manager->count();
        if ($sessionCount > 1) {
            $activeIdx = $manager->getActiveIndex() + 1;
            $header->addLeft(" [{$activeIdx}/{$sessionCount}] ", Color::Gray400);
        }

        // Streaming indicator
        if ($prompt->isStreaming()) {
            $header->addRight(' Streaming... ', Color::Gray900, Color::Yellow, bold: true);
        } else {
            $header->addRight(' Ready ', Color::White);
        }

        // Mode badge
        $header->addRight(" {$mode} ", Color::Gray900, $modeColor, bold: true);

        return $header;
    }

    protected function sessionsPanel(CodePrompt $prompt, int $width, int $height): Panel
    {
        $isFocused = $prompt->getFocusedPanel() === 'sessions';
        $manager = $prompt->getSessionManager();
        $sessions = $manager->all();
        $cursorIndex = $manager->getCursorIndex();
        $activeChat = $manager->active();

        $accordion = Accordion::make('session-list')
            ->fg($isFocused ? Color::Gray300 : Color::Gray500)
            ->bg($isFocused ? Color::Gray800 : Color::Gray900)
            ->selectedColors(Color::Gray700, Color::White)
            ->activeFg(Color::Cyan);

        if (empty($sessions)) {
            $accordion->section(
                key: 'empty',
                label: 'No sessions',
                items: [],
                icon: '○',
                color: Color::Gray500,
            );
        } else {
            foreach ($sessions as $idx => $chat) {
                $isActive = $activeChat && $chat->id === $activeChat->id;

                // Status indicator
                $statusIcon = match ($chat->status) {
                    'streaming' => '◉',
                    'thinking' => '⟳',
                    'error' => '✗',
                    default => $isActive ? '●' : '○',
                };
                $statusColor = match ($chat->status) {
                    'streaming' => Color::Yellow,
                    'thinking' => Color::Cyan,
                    'error' => Color::Red,
                    default => $isActive ? Color::Green : Color::Gray500,
                };

                // Detail items shown when expanded
                $items = [];
                if (! empty($chat->description)) {
                    $items[] = $chat->description;
                }
                $items[] = $chat->worktree;
                $messageCount = $chat->session?->getMessages()
                    ->filter(fn (array $m) => in_array($m['role'], ['user', 'assistant']))
                    ->count() ?? 0;
                $items[] = "{$messageCount} messages";
                $tokens = $chat->estimateTokens();
                if ($tokens > 0) {
                    $items[] = "~{$this->formatTokenCount($tokens)} tokens";
                }

                $accordion->section(
                    key: $idx,
                    label: $chat->name,
                    items: $items,
                    icon: $statusIcon,
                    color: $statusColor,
                );
            }
        }

        // Expand based on SessionManager state
        $accordion->isExpanded(fn ($key) => isset($sessions[$key]) && $manager->isExpanded($sessions[$key]->id));
        $accordion->isSectionSelected(fn ($key) => $key === $cursorIndex);

        return Panel::make('sessions-panel')
            ->sidebar()
            ->title('Sessions')
            ->body($accordion)
            ->width($width)
            ->height($height)
            ->headerColors(Color::Gray600, Color::White)
            ->bodyColors(Color::Gray900)
            ->focusedColors(Color::Yellow, Color::Gray800)
            ->focused($isFocused);
    }

    protected function toolsPanel(CodePrompt $prompt, int $width, int $height): Panel
    {
        $isFocused = $prompt->getFocusedPanel() === 'tools';
        $history = $prompt->getToolCallHistory();

        $accordion = Accordion::make('tool-history')
            ->fg($isFocused ? Color::Gray300 : Color::Gray500)
            ->bg($isFocused ? Color::Gray800 : Color::Gray900)
            ->selectedColors(Color::Gray700, Color::White)
            ->activeFg(Color::Cyan);

        if (empty($history)) {
            $accordion->section(
                key: 'empty',
                label: 'No tool calls yet',
                items: [],
                icon: '○',
                color: Color::Gray500,
            );
        } else {
            // Group tool calls and show most recent first
            $recentCalls = array_reverse(array_slice($history, -20));

            foreach ($recentCalls as $idx => $call) {
                $name = $call['name'] ?? 'unknown';
                $success = $call['result']['success'] ?? false;
                $icon = $success ? '✓' : '✗';
                $color = $success ? Color::Green : Color::Red;

                $items = [];
                foreach ($call['arguments'] ?? [] as $key => $value) {
                    $valStr = is_array($value) ? json_encode($value) : (string) $value;
                    if (strlen($valStr) > 30) {
                        $valStr = substr($valStr, 0, 27).'...';
                    }
                    $items[] = "{$key}: {$valStr}";
                }

                $accordion->section(
                    key: $idx,
                    label: $name,
                    items: $items,
                    icon: $icon,
                    color: $color,
                );
            }
        }

        // Expand the most recent call
        $accordion->isExpanded(fn ($key) => $key === 0 && ! empty($history));

        return Panel::make('tools-panel')
            ->sidebar()
            ->title('Tools')
            ->body($accordion)
            ->width($width)
            ->height($height)
            ->headerColors(Color::Gray600, Color::White)
            ->bodyColors(Color::Gray900)
            ->focusedColors(Color::Magenta, Color::Gray800)
            ->focused($isFocused);
    }

    protected function conversationPanel(CodePrompt $prompt, int $width, int $height): Panel
    {
        $isFocused = $prompt->getFocusedPanel() === 'conversation';

        // Build the body: messages + divider + input
        $body = $this->buildConversationBody($prompt, $width, $height);

        $title = $prompt->isStreaming() ? 'Conversation (streaming...)' : 'Conversation';

        return Panel::make('conversation-panel')
            ->sidebar()
            ->title($title)
            ->body($body)
            ->width($width)
            ->height($height)
            ->headerColors(Color::Gray600, Color::White)
            ->bodyColors(Color::Gray900)
            ->focusedColors(Color::Cyan, Color::Gray800)
            ->focused($isFocused);
    }

    protected function buildConversationBody(CodePrompt $prompt, int $panelWidth, int $panelHeight): string
    {
        $innerWidth = max(10, $panelWidth - 4);
        $availableHeight = max(5, $panelHeight - 1); // -1 for panel header (handled by panel renderer)

        // Sync and render the TextArea component for the input section
        $prompt->syncTextArea($innerWidth);
        $isInsertMode = $prompt->getMode() === 'insert';

        $hasInput = ! empty(trim($prompt->getTypedValue()));

        // Build the hint line (always visible below input)
        $hintLine = match (true) {
            $isInsertMode => $this->style(' Esc', Color::Yellow, bold: true)
                .$this->style(':Normal ', Color::Gray600)
                .$this->style('Enter', Color::Gray500)
                .$this->style(':Newline ', Color::Gray600)
                .$this->style('~', Color::Gray500)
                .$this->style(':File', Color::Gray600),
            $hasInput => $this->style(' Enter', Color::Green, bold: true)
                .$this->style(':Send ', Color::Gray600)
                .$this->style('i', Color::Yellow, bold: true)
                .$this->style(':Edit', Color::Gray600),
            default => $this->style(' i', Color::Yellow, bold: true)
                .$this->style(':Insert ', Color::Gray600)
                .$this->style(':', Color::Gray500)
                .$this->style(':Command ', Color::Gray600)
                .$this->style('?', Color::Gray500)
                .$this->style(':Help', Color::Gray600),
        };

        if ($isInsertMode) {
            $rendered = $prompt->inputTextArea->render();
            // Add left padding to each rendered line
            $renderedLines = explode("\n", $rendered);
            $renderedLines = array_map(fn ($line) => ' '.$line, $renderedLines);
            // Clamp textarea to max 10 visual lines
            if (count($renderedLines) > 10) {
                $renderedLines = array_slice($renderedLines, count($renderedLines) - 10);
            }
            $inputSection = implode("\n", $renderedLines)."\n".$hintLine;
        } elseif ($hasInput) {
            $text = $this->style(' '.$prompt->getTypedValue(), Color::Gray400);
            $inputSection = $text."\n".$hintLine;
        } else {
            $inputSection = $hintLine;
        }

        // File context indicator (separate from status line)
        $contextIndicator = '';
        $contextIndicatorLines = 0;
        if ($prompt->hasFileContexts()) {
            $contextIndicator = $this->buildFileContextIndicator($prompt, $innerWidth);
            $contextIndicatorLines = substr_count($contextIndicator, "\n") + 1;
        }

        // Status line just above the input: thinking animation, streaming counter, or settled token count
        $hasMessages = ! empty($prompt->getConversationForDisplay());
        $statusSection = '';
        $statusLines = 0;
        if ($prompt->isThinking()) {
            $statusSection = $this->buildThinkingIndicator($prompt, $innerWidth);
            $statusLines = 1;
        } elseif ($prompt->isStreaming()) {
            $statusSection = $this->buildStreamingCounter($prompt, $innerWidth);
            $statusLines = 1;
        } elseif ($hasMessages) {
            $statusSection = $this->buildTokenStatus($prompt);
            $statusLines = 1;
        }

        $inputLines = substr_count($inputSection, "\n") + 1;
        $messageHeight = max(2, $availableHeight - $inputLines - $contextIndicatorLines - $statusLines - 1);

        $messageBody = $this->buildMessageBody($prompt, $innerWidth, $messageHeight);

        // Pad messages to fill available height so input is pinned to the bottom
        $messageLineCount = substr_count($messageBody, "\n") + 1;
        if ($messageLineCount < $messageHeight) {
            $messageBody .= str_repeat("\n", $messageHeight - $messageLineCount);
        }

        $divider = $this->style(str_repeat('─', $innerWidth), Color::Gray700);

        $parts = [$messageBody, $divider];
        if ($contextIndicator !== '') {
            $parts[] = $contextIndicator;
        }
        if ($statusSection !== '') {
            $parts[] = $statusSection;
        }
        $parts[] = $inputSection;

        return implode("\n", $parts);
    }

    protected function buildMessageBody(CodePrompt $prompt, int $width, int $maxLines): string
    {
        $messages = $prompt->getConversationForDisplay();

        if (empty($messages)) {
            return $this->style(' Type a message to start...', Color::Gray500);
        }

        $allLines = [];

        foreach ($messages as $msg) {
            $role = $msg['role'];
            $content = $msg['content'] ?? '';
            $isStreaming = $msg['streaming'] ?? false;

            if ($role === 'assistant' && empty($content)) {
                continue;
            }

            // Collapse file context messages into a single compact line
            if ($role === 'user') {
                $collapsed = $this->collapseFileContext($content);
                if ($collapsed !== null) {
                    if (empty($allLines)) {
                        $allLines[] = ''; // Padding from panel top
                    }
                    $allLines[] = $this->style('  '.$collapsed, Color::Gray500);

                    continue;
                }
            }

            // Role header
            $roleLabel = match ($role) {
                'user' => $this->style(' You', Color::Green, bold: true),
                'assistant' => $isStreaming
                    ? $this->style(' Assistant', Color::Yellow, bold: true).$this->style(' ▍', Color::Yellow)
                    : $this->style(' Assistant', Color::Cyan, bold: true),
                'tool_summary' => $this->style(' Tools', Color::Magenta, bold: true),
                default => $this->style(' '.ucfirst($role), Color::Gray500),
            };

            $allLines[] = $roleLabel;

            // Word wrap content using grapheme-aware width
            $contentWidth = max(10, $width - 3);
            $wrapped = $this->wrapContent($content, $contentWidth);

            foreach (explode("\n", $wrapped) as $line) {
                $color = match ($role) {
                    'user' => Color::White,
                    'assistant' => $isStreaming ? Color::Yellow : Color::Gray300,
                    'tool_summary' => Color::Gray400,
                    default => Color::Gray500,
                };
                $allLines[] = $this->style('  '.$line, $color);
            }

            $allLines[] = '';
        }

        // Remove trailing empty
        if (! empty($allLines) && $allLines[count($allLines) - 1] === '') {
            array_pop($allLines);
        }

        $totalLines = count($allLines);
        $scrollOffset = $prompt->getScrollOffset();

        if ($totalLines <= $maxLines) {
            $visibleLines = $allLines;
        } else {
            $endPos = $totalLines - $scrollOffset;
            $startPos = max(0, $endPos - $maxLines);
            $endPos = min($totalLines, $startPos + $maxLines);

            $visibleLines = array_slice($allLines, $startPos, $endPos - $startPos);

            if ($startPos > 0) {
                $visibleLines[0] = $this->style(' ↑ more above ↑', Color::Gray500);
            }
            if ($endPos < $totalLines) {
                $visibleLines[count($visibleLines) - 1] = $this->style(' ↓ more below ↓', Color::Gray500);
            }
        }

        return implode("\n", $visibleLines);
    }

    protected function contextPanel(CodePrompt $prompt, int $width, int $height): Panel
    {
        $isFocused = $prompt->getFocusedPanel() === 'context';

        $stack = Stack::make('context-content');

        // File contexts
        $contexts = $prompt->getFileContexts();
        if ($contexts->isNotEmpty()) {
            $stack->add(
                Text::make('ctx-header', ' File Contexts')
                    ->fg(Color::Green)
                    ->bold()
            );

            foreach ($contexts as $idx => $context) {
                $display = $context->relativePath;
                if (strlen($display) > $width - 6) {
                    $display = '...'.substr($display, -(($width) - 9));
                }

                $lineInfo = $context->hasLineRange()
                    ? " L{$context->startLine}-{$context->endLine}"
                    : " {$context->totalLines}L";

                $stack->add(
                    Text::make("ctx-file-{$idx}", "  {$display}")
                        ->fg($isFocused ? Color::Gray300 : Color::Gray500)
                );
                $stack->add(
                    Text::make("ctx-lines-{$idx}", "  {$lineInfo}")
                        ->fg(Color::Gray600)
                );
            }
        } else {
            $stack->add(
                Text::make('ctx-empty', ' No file contexts')
                    ->fg(Color::Gray500)
            );
            $stack->add(
                Text::make('ctx-hint', ' Press ~ to add files')
                    ->fg(Color::Gray600)
            );
        }

        // Section padding
        $stack->add(Text::make('ctx-div', ' '));

        // Session metadata
        $session = $prompt->getSession();
        $sessionId = $session?->getId() ? substr($session->getId(), 0, 8) : 'none';
        $messageCount = $session?->getMessages()
            ->filter(fn (array $m) => in_array($m['role'], ['user', 'assistant']))
            ->count() ?? 0;

        $stack->add(
            Text::make('session-header', ' Session')
                ->fg(Color::Cyan)
                ->bold()
        );
        $stack->add(
            Text::make('session-id', "  ID: {$sessionId}")
                ->fg($isFocused ? Color::Gray300 : Color::Gray500)
        );
        $stack->add(
            Text::make('session-msgs', "  Messages: {$messageCount}")
                ->fg($isFocused ? Color::Gray300 : Color::Gray500)
        );

        // Provider info
        $provider = $prompt->getProviderManager();
        if ($provider) {
            $stack->add(Text::make('provider-div', ' '));
            $stack->add(
                Text::make('provider-header', ' Provider')
                    ->fg(Color::Yellow)
                    ->bold()
            );
            $stack->add(
                Text::make('provider-name', '  '.($provider->getCurrentProvider() ?? 'none'))
                    ->fg($isFocused ? Color::Gray300 : Color::Gray500)
            );
            $stack->add(
                Text::make('provider-model', '  '.($provider->getModel() ?? 'none'))
                    ->fg($isFocused ? Color::Gray300 : Color::Gray500)
            );

            // Context window usage
            $contextWindow = $provider->getContextWindowSize();
            $tokenUsage = $session?->estimateTokenUsage() ?? 0;
            $usagePercent = $contextWindow > 0 ? min(100, (int) round($tokenUsage / $contextWindow * 100)) : 0;

            $usageColor = match (true) {
                $usagePercent >= 80 => Color::Red,
                $usagePercent >= 50 => Color::Yellow,
                default => Color::Green,
            };

            $stack->add(Text::make('context-div', ' '));
            $stack->add(
                Text::make('context-header', ' Context Window')
                    ->fg(Color::Cyan)
                    ->bold()
            );
            $stack->add(
                Text::make('context-usage', "  ~{$this->formatTokenCount($tokenUsage)} / {$this->formatTokenCount($contextWindow)}")
                    ->fg($usageColor)
            );

            // Usage bar
            $barWidth = max(6, $width - 6);
            $filled = (int) round($barWidth * $usagePercent / 100);
            $bar = str_repeat('█', $filled).str_repeat('░', $barWidth - $filled);
            $stack->add(
                Text::make('context-bar', "  {$bar} {$usagePercent}%")
                    ->fg($usageColor)
            );
        }

        // Pusher status
        if ($prompt->isPusherConnected()) {
            $stack->add(Text::make('pusher-div', ' '));
            $stack->add(
                Text::make('pusher-status', ' ● Connected')
                    ->fg(Color::Green)
                    ->bold()
            );
            $channel = $prompt->getPusherChannel();
            if ($channel) {
                $stack->add(
                    Text::make('pusher-channel', "  {$channel}")
                        ->fg(Color::Gray500)
                );
            }
        }

        return Panel::make('context-panel')
            ->sidebar()
            ->title('Context')
            ->body($stack)
            ->width($width)
            ->height($height)
            ->headerColors(Color::Gray600, Color::White)
            ->bodyColors(Color::Gray900)
            ->focusedColors(Color::Green, Color::Gray800)
            ->focused($isFocused);
    }

    protected function statusBar(CodePrompt $prompt): StatusBar
    {
        $manager = $prompt->getSessionManager();
        $activeChat = $manager->active();
        $sessionName = $activeChat?->name ?? 'none';
        $sessionCount = $manager->count();
        $activeIdx = $manager->getActiveIndex() + 1;

        $left = $this->style(" {$sessionName}", Color::Cyan);
        if ($sessionCount > 1) {
            $left .= $this->style(" [{$activeIdx}/{$sessionCount}]", Color::Gray500);
        }
        $left .= $this->style(' │ ', Color::Gray600)
            .$this->style($prompt->getProviderManager()?->getModel() ?? 'no model', Color::Gray400);

        $scrollOffset = $prompt->getScrollOffset();
        $center = $scrollOffset > 0
            ? $this->style(" ↑{$scrollOffset}", Color::Yellow)
            : '';

        $right = match ($prompt->getMode()) {
            'insert' => $this->style('Esc', Color::Yellow).$this->style(':Normal ', Color::Gray500)
                      .$this->style('Enter', Color::Yellow).$this->style(':Newline ', Color::Gray500)
                      .$this->style('Tab', Color::Yellow).$this->style(':Indent ', Color::Gray500)
                      .$this->style('~', Color::Yellow).$this->style(':File ', Color::Gray500),
            'command' => $this->style('Enter', Color::Yellow).$this->style(':Run ', Color::Gray500)
                       .$this->style('Esc', Color::Yellow).$this->style(':Cancel ', Color::Gray500)
                       .$this->style('Tab', Color::Yellow).$this->style(':Complete ', Color::Gray500),
            default => $this->style('Enter', Color::Yellow).$this->style(':Send ', Color::Gray500)
                     .$this->style('Tab', Color::Yellow).$this->style(':Panel ', Color::Gray500)
                     .$this->style('i', Color::Yellow).$this->style(':Insert ', Color::Gray500)
                     .$this->style(':', Color::Yellow).$this->style(':Cmd ', Color::Gray500)
                     .$this->style('?', Color::Yellow).$this->style(':Help ', Color::Gray500),
        };

        return StatusBar::make('status-bar')
            ->left($left)
            ->center($center)
            ->right($right)
            ->bg(Color::Gray700);
    }

    protected function commandLine(CodePrompt $prompt): CommandLine
    {
        return CommandLine::make('command-line')
            ->mode($prompt->getMode())
            ->buffer($prompt->getCommandBuffer())
            ->promptChar(':')
            ->promptColor(Color::Yellow)
            ->textColor(Color::White)
            ->cursorColor(Color::Cyan)
            ->bg(Color::Gray900);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODAL BUILDERS
    // ─────────────────────────────────────────────────────────────────────────

    protected function buildModal(CodePrompt $prompt): ?Modal
    {
        return match ($prompt->getActiveModalType()) {
            'help' => $this->helpModal(),
            'resume' => $this->resumeModal($prompt),
            'context' => $this->contextModal($prompt),
            'command_palette' => $this->commandPaletteModal($prompt),
            'bash_approval' => $this->bashApprovalModal($prompt),
            'file_tree' => $this->fileTreeModal($prompt),
            default => null,
        };
    }

    protected function helpModal(): Modal
    {
        $shortcuts = [
            'Modes',
            '  i                  Enter insert mode',
            '  Esc                Return to normal mode',
            '  :                  Enter command mode',
            '',
            'Normal Mode',
            '  Enter              Send message',
            '  Tab / Shift+Tab    Cycle panels',
            '  j / k              Scroll up/down',
            '  G                  Scroll to bottom',
            '  ? / q              Help / Quit',
            '  ~ / @              Open file browser',
            '',
            'Insert Mode (multiline)',
            '  Enter              Insert newline',
            '  Tab                Insert tab',
            '  /                  Slash commands',
            '  ~ / @              Add file context',
            '  Esc                Normal mode to send',
            '',
            'Sessions Panel',
            '  j / k              Navigate sessions',
            '  Enter / l          Switch to session',
            '  n                  New session',
            '  d                  Delete session',
            '',
            'Commands',
            '  :help              Show this help',
            '  :clear             Clear conversation',
            '  :save              Save session',
            '  :resume            Resume session',
            '  :new               New chat session',
            '  :sessions          Focus sessions panel',
            '  :quit              Quit',
            '  :model <name>      Switch AI model',
        ];

        $body = implode("\n", array_map(function ($line) {
            if ($line === '' || $line[0] !== ' ') {
                return $this->style(' '.$line, Color::Yellow, bold: true);
            }

            return $this->style(' '.$line, Color::Gray300);
        }, $shortcuts));

        return Modal::make('help-modal')
            ->title('Keyboard Shortcuts')
            ->body($body)
            ->size(60, 36)
            ->borderColor(Color::Cyan)
            ->bodyBg(Color::Gray800);
    }

    protected function resumeModal(CodePrompt $prompt): Modal
    {
        $state = $prompt->getModalState();
        $sessions = $state['sessions'] ?? [];
        $selectedIndex = $state['selectedIndex'] ?? 0;

        $selectList = SelectList::make('resume-list')
            ->items($sessions)
            ->selectedIndex($selectedIndex)
            ->selectedColors(Color::White)
            ->fg(Color::Gray400)
            ->itemRenderer(fn ($session, $_i, $isSelected) => $this->style(
                ($isSelected ? ' ▶ ' : '   ').($session['label'] ?? 'Unknown'),
                $isSelected ? Color::White : Color::Gray400,
            ).$this->style(' '.($session['description'] ?? ''), Color::Gray500));

        $body = $this->style(' Select a session to resume:', Color::Gray300)."\n\n"
            .$selectList->setContainer(new Container(46, max(1, count($sessions))))->render()
            ."\n\n".$this->style(' ↑↓ navigate  Enter resume  Esc close', Color::Gray500);

        return Modal::make('resume-modal')
            ->title('Resume Session')
            ->body($body)
            ->size(50, min(20, count($sessions) + 8))
            ->borderColor(Color::Cyan)
            ->bodyBg(Color::Gray800);
    }

    protected function contextModal(CodePrompt $prompt): Modal
    {
        $state = $prompt->getModalState();
        $options = $state['options'] ?? [];
        $selectedIndex = $state['selectedIndex'] ?? 0;

        $selectList = SelectList::make('context-list')
            ->items($options)
            ->selectedIndex($selectedIndex)
            ->selectedColors(Color::White)
            ->fg(Color::Gray400)
            ->itemRenderer(fn ($opt, $_i, $isSelected) => $this->style(
                ($isSelected ? ' ▶ ' : '   ').($opt['label'] ?? ''),
                $isSelected ? Color::White : Color::Gray400,
            ).$this->style(' '.($opt['description'] ?? ''), Color::Gray500));

        $body = $this->style(' File Contexts:', Color::Gray300)."\n\n"
            .$selectList->setContainer(new Container(46, max(1, count($options))))->render()
            ."\n\n".$this->style(' ↑↓ navigate  Enter select  Esc close', Color::Gray500);

        return Modal::make('context-modal')
            ->title('File Contexts')
            ->body($body)
            ->size(50, min(20, count($options) + 8))
            ->borderColor(Color::Green)
            ->bodyBg(Color::Gray800);
    }

    protected function commandPaletteModal(CodePrompt $prompt): Modal
    {
        $state = $prompt->getModalState();
        $commands = $state['commands'] ?? [];
        $selectedIndex = $state['selectedIndex'] ?? 0;

        $selectList = SelectList::make('command-list')
            ->items($commands)
            ->selectedIndex($selectedIndex)
            ->itemRenderer(fn ($cmd, $_i, $isSelected) => $this->style(
                ($isSelected ? ' ▶ ' : '   ').($cmd['label'] ?? ''),
                $isSelected ? Color::White : Color::Gray400,
            ));

        $body = $this->style(' > ', Color::Cyan).$this->style('Select a command', Color::Gray300)."\n\n"
            .$selectList->setContainer(new Container(46, max(1, count($commands))))->render()
            ."\n\n".$this->style(' ↑↓ navigate  Enter execute  Esc close', Color::Gray500);

        return Modal::make('command-palette-modal')
            ->title('Command Palette')
            ->body($body)
            ->size(50, min(18, count($commands) + 8))
            ->borderColor(Color::Magenta)
            ->bodyBg(Color::Gray800);
    }

    protected function bashApprovalModal(CodePrompt $prompt): Modal
    {
        $state = $prompt->getModalState();
        $command = $state['command'] ?? 'unknown';
        $workingDir = $state['working_directory'] ?? getcwd();

        $body = $this->style(' The AI wants to run:', Color::Gray300)."\n\n"
            .$this->style(' $ ', Color::Yellow).$this->style($command, Color::White, bold: true)."\n\n"
            .$this->style(' Directory: ', Color::Gray500).$this->style($workingDir, Color::Gray400)."\n\n"
            .$this->style(' ─────────────────────────────────────', Color::Gray700)."\n\n"
            .$this->style(' y', Color::Green, bold: true).$this->style(' / ', Color::Gray500)
            .$this->style('Enter', Color::Green, bold: true).$this->style('  Approve', Color::Gray300)."\n"
            .$this->style(' n', Color::Red, bold: true).$this->style(' / ', Color::Gray500)
            .$this->style('Esc', Color::Red, bold: true).$this->style('    Deny', Color::Gray300);

        return Modal::make('bash-approval-modal')
            ->title('Bash Command Approval')
            ->body($body)
            ->size(55, 14)
            ->borderColor(Color::Yellow)
            ->bodyBg(Color::Gray800);
    }

    protected function fileTreeModal(CodePrompt $prompt): ?Modal
    {
        $fileTree = $prompt->getFileTreeComponent();
        if (! $fileTree) {
            return null;
        }

        // Render the file tree component into a modal body
        $fileTree->setContainer(new Container(56, 20));
        $body = $fileTree->render();

        return Modal::make('file-tree-modal')
            ->title('File Browser')
            ->body($body)
            ->size(60, 24)
            ->borderColor(Color::Green)
            ->bodyBg(Color::Gray800);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOAST
    // ─────────────────────────────────────────────────────────────────────────

    protected function buildToast(CodePrompt $prompt): ?Toast
    {
        $message = $prompt->getToastMessage();
        if ($message === null) {
            return null;
        }

        return Toast::make('notification')
            ->message($message)
            ->type('success')
            ->progress($prompt->getToastProgress())
            ->yPosition(2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UTILITY — styling helper for pre-styled content in component props
    // ─────────────────────────────────────────────────────────────────────────

    protected function buildThinkingIndicator(CodePrompt $prompt, int $width): string
    {
        $frame = $prompt->getThinkingFrame();
        $elapsed = $prompt->getThinkingElapsed();

        // Braille spinner
        $spinnerFrames = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
        $spinner = $spinnerFrames[$frame % count($spinnerFrames)];

        // Cycling status text
        $labels = ['Thinking', 'Reasoning', 'Composing', 'Analyzing'];
        $labelIndex = (int) floor($elapsed / 1.5) % count($labels);
        $dotCount = ($frame / 4) % 4;
        $label = $labels[$labelIndex].str_repeat('.', (int) $dotCount);
        $labelPadded = str_pad($label, 12); // Stable width so wave doesn't jump

        // Elapsed time
        $seconds = (int) $elapsed;
        $time = $seconds > 0 ? " {$seconds}s" : '';

        // Build the fixed prefix: " ⠋ Thinking...  3s  "
        $prefix = " {$spinner} {$labelPadded}{$time}  ";
        $prefixWidth = mb_strwidth($prefix);

        // Wave fills the remaining width
        $barWidth = max(4, $width - $prefixWidth - 1);
        $bar = '';
        $blockChars = ['·', '∙', ':', '∷', ':', '∙'];
        $waveColors = [Color::Gray700, Color::Gray600, Color::Gray500, Color::Gray600, Color::Gray700];

        $speed = $frame * 0.3;

        for ($i = 0; $i < $barWidth; $i++) {
            $pos = $i / $barWidth;

            // Density: two sine waves moving rightward
            $wave1 = sin($pos * M_PI * 3.0 - $speed);
            $wave2 = sin($pos * M_PI * 5.0 - $speed * 1.7);
            $combined = ($wave1 + $wave2 + 2.0) / 4.0;

            $charIdx = (int) floor($combined * (count($blockChars) - 0.01));
            $char = $blockChars[$charIdx];

            // Color: opposite direction so it washes through the texture
            $colorWave = sin($pos * M_PI * 4.0 + $speed * 0.7);
            $colorIdx = (int) floor((($colorWave + 1.0) / 2.0) * (count($waveColors) - 0.01));
            $color = $waveColors[$colorIdx];

            $bar .= $this->style($char, $color);
        }

        return $this->style(" {$spinner} ", Color::Cyan, bold: true)
            .$this->style($labelPadded, Color::Gray300)
            .$this->style($time, Color::Gray500)
            .'  '.$bar;
    }

    protected function buildStreamingCounter(CodePrompt $prompt, int $width): string
    {
        $response = $prompt->getStreamingResponse();
        $responseTokens = (int) ceil(mb_strlen($response) / 4);
        $totalTokens = $prompt->getSession()?->estimateTokenUsage() ?? 0;
        $contextWindow = $prompt->getProviderManager()?->getContextWindowSize() ?? 0;

        $responseCount = $this->formatTokenCount($responseTokens);
        $totalCount = $this->formatTokenCount($totalTokens + $responseTokens);

        $bounceChars = ['▏', '▎', '▍', '▌', '▋', '▊', '▉', '█', '▉', '▊', '▋', '▌', '▍', '▎'];
        $bounceIdx = (int) (microtime(true) * 8) % count($bounceChars);
        $cursor = $bounceChars[$bounceIdx];

        $line = $this->style(" {$cursor} ", Color::Yellow)
            .$this->style('Streaming', Color::Gray300)
            .$this->style('  ↑ ', Color::Gray600)
            .$this->style($responseCount, Color::Cyan, bold: true)
            .$this->style(' tokens', Color::Gray500);

        if ($contextWindow > 0) {
            $line .= $this->style('  · ', Color::Gray700)
                .$this->style($totalCount, Color::Gray400)
                .$this->style(' / '.$this->formatTokenCount($contextWindow), Color::Gray600);
        }

        return $line;
    }

    protected function buildTokenStatus(CodePrompt $prompt): string
    {
        $totalTokens = $prompt->getSession()?->estimateTokenUsage() ?? 0;
        $contextWindow = $prompt->getProviderManager()?->getContextWindowSize() ?? 0;

        $totalCount = $this->formatTokenCount($totalTokens);

        $line = $this->style(' ○ ', Color::Gray600)
            .$this->style($totalCount, Color::Gray400)
            .$this->style(' tokens', Color::Gray600);

        if ($contextWindow > 0) {
            $usagePercent = min(100, (int) round($totalTokens / $contextWindow * 100));
            $usageColor = match (true) {
                $usagePercent >= 80 => Color::Red,
                $usagePercent >= 50 => Color::Yellow,
                default => Color::Gray500,
            };

            $line .= $this->style(' / '.$this->formatTokenCount($contextWindow), Color::Gray700)
                .$this->style("  {$usagePercent}%", $usageColor);
        }

        return $line;
    }

    protected function buildFileContextIndicator(CodePrompt $prompt, int $width): string
    {
        $contexts = $prompt->getFileContexts();
        $parts = [];

        foreach ($contexts as $context) {
            $path = $context->relativePath;
            $filename = basename($path);
            $dir = dirname($path);
            if ($dir !== '.') {
                $maxDirWidth = max(5, 25 - StringUtils::width($filename));
                if (StringUtils::width($dir) > $maxDirWidth) {
                    $dir = '...'.substr($dir, -(max(2, $maxDirWidth - 3)));
                }
                $name = $dir.'/'.$filename;
            } else {
                $name = $filename;
            }
            $range = $context->hasLineRange() ? ":{$context->startLine}-{$context->endLine}" : '';
            $parts[] = $this->style($name.$range, Color::Cyan);
        }

        return $this->style(' 📎 ', Color::Gray500)
            .implode($this->style(' · ', Color::Gray700), $parts);
    }

    protected function collapseFileContext(string $content): ?string
    {
        if (preg_match('/^```\nFile: (.+?)(?:\n---\n[\s\S]*)?```$/s', $content, $matches)) {
            $header = trim($matches[1]);

            return "📎 {$header}";
        }

        return null;
    }

    protected function wrapContent(string $text, int $width): string
    {
        $result = [];

        foreach (explode("\n", $text) as $paragraph) {
            if (StringUtils::width($paragraph) <= $width) {
                $result[] = $paragraph;

                continue;
            }

            $words = explode(' ', $paragraph);
            $line = '';
            $lineWidth = 0;

            foreach ($words as $word) {
                $wordWidth = StringUtils::width($word);

                // Break long words
                if ($wordWidth > $width && $line === '') {
                    $result[] = StringUtils::truncate($word, $width);

                    continue;
                }

                $newWidth = $lineWidth + ($line !== '' ? 1 : 0) + $wordWidth;

                if ($newWidth <= $width) {
                    $line = $line !== '' ? $line.' '.$word : $word;
                    $lineWidth = $newWidth;
                } else {
                    if ($line !== '') {
                        $result[] = $line;
                    }
                    $line = $word;
                    $lineWidth = $wordWidth;
                }
            }

            if ($line !== '') {
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    protected function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return round($tokens / 1_000_000, 1).'M';
        }
        if ($tokens >= 1_000) {
            return round($tokens / 1_000, 1).'K';
        }

        return (string) $tokens;
    }

    protected function style(string $text, ?Color $fg = null, ?Color $bg = null, bool $bold = false): string
    {
        $codes = [];
        if ($bold) {
            $codes[] = '1';
        }
        if ($fg !== null) {
            $codes[] = $fg->fg();
        }
        if ($bg !== null) {
            $codes[] = $bg->bg();
        }

        if (empty($codes)) {
            return $text;
        }

        return "\e[".implode(';', $codes)."m{$text}\e[0m";
    }
}
