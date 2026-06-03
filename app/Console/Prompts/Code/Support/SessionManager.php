<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Support;

class SessionManager
{
    /** @var array<string, ChatSession> */
    protected array $sessions = [];

    protected ?string $activeSessionId = null;

    protected int $cursorIndex = 0;

    /** @var array<string, bool> Session IDs that are collapsed */
    protected array $collapsed = [];

    public function add(ChatSession $session): void
    {
        $this->sessions[$session->id] = $session;

        if ($this->activeSessionId === null) {
            $this->activeSessionId = $session->id;
        }
    }

    public function remove(string $id): void
    {
        unset($this->sessions[$id]);

        if ($this->activeSessionId === $id) {
            $this->activeSessionId = array_key_first($this->sessions);
        }

        $this->cursorIndex = min($this->cursorIndex, max(0, count($this->sessions) - 1));
    }

    public function get(string $id): ?ChatSession
    {
        return $this->sessions[$id] ?? null;
    }

    public function active(): ?ChatSession
    {
        if ($this->activeSessionId === null) {
            return null;
        }

        return $this->sessions[$this->activeSessionId] ?? null;
    }

    public function setActive(string $id): void
    {
        if (isset($this->sessions[$id])) {
            $this->activeSessionId = $id;
        }
    }

    /** @return ChatSession[] */
    public function all(): array
    {
        return array_values($this->sessions);
    }

    public function count(): int
    {
        return count($this->sessions);
    }

    public function cursorUp(): void
    {
        $count = count($this->sessions);
        if ($count === 0) {
            return;
        }
        $this->cursorIndex = ($this->cursorIndex - 1 + $count) % $count;
    }

    public function cursorDown(): void
    {
        $count = count($this->sessions);
        if ($count === 0) {
            return;
        }
        $this->cursorIndex = ($this->cursorIndex + 1) % $count;
    }

    public function getCursorIndex(): int
    {
        return $this->cursorIndex;
    }

    public function selectAtCursor(): void
    {
        $sessions = array_values($this->sessions);
        if (isset($sessions[$this->cursorIndex])) {
            $this->activeSessionId = $sessions[$this->cursorIndex]->id;
        }
    }

    public function toggleExpandedAtCursor(): void
    {
        $sessions = array_values($this->sessions);
        if (isset($sessions[$this->cursorIndex])) {
            $id = $sessions[$this->cursorIndex]->id;
            if (isset($this->collapsed[$id])) {
                unset($this->collapsed[$id]);
            } else {
                $this->collapsed[$id] = true;
            }
        }
    }

    public function isExpanded(string $id): bool
    {
        return ! isset($this->collapsed[$id]);
    }

    public function getActiveIndex(): int
    {
        $sessions = array_values($this->sessions);
        foreach ($sessions as $index => $session) {
            if ($session->id === $this->activeSessionId) {
                return $index;
            }
        }

        return 0;
    }

    public function hasStreaming(): bool
    {
        foreach ($this->sessions as $session) {
            if ($session->isStreaming) {
                return true;
            }
        }

        return false;
    }

    public function getSessionStatuses(): array
    {
        $statuses = [];
        foreach ($this->sessions as $session) {
            $statuses[$session->id] = $session->status;
        }

        return $statuses;
    }
}
