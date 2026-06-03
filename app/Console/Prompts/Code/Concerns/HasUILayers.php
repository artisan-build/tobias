<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Concerns;

use ArtisanBuild\Parfait\Components\FileTree;

trait HasUILayers
{
    protected ?FileTree $fileTreeComponent = null;

    protected ?string $savedTypedValue = null;

    protected ?int $savedCursorPosition = null;

    protected function saveTypedValue(): void
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $this->savedTypedValue = $chat->typedValue;
            $this->savedCursorPosition = $chat->cursorPosition;
        }
    }

    protected function restoreTypedValue(): void
    {
        $chat = $this->sessionManager->active();
        if ($chat && $this->savedTypedValue !== null) {
            $chat->typedValue = $this->savedTypedValue;
        }
        if ($chat && $this->savedCursorPosition !== null) {
            $chat->cursorPosition = $this->savedCursorPosition;
        }
    }

    protected function clearSavedTypedValue(): void
    {
        $this->savedTypedValue = null;
        $this->savedCursorPosition = null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MODAL
    // ─────────────────────────────────────────────────────────────────────────

    public function hasActiveModal(): bool
    {
        return $this->activeModalType !== null;
    }

    public function getActiveModal(): ?string
    {
        return $this->activeModalType;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FILE TREE
    // ─────────────────────────────────────────────────────────────────────────

    public function showFileTree(?string $basePath = null): void
    {
        $this->saveTypedValue();
        $path = $basePath ?? getcwd();

        $this->fileTreeComponent = FileTree::make('file-browser')
            ->basePath($path)
            ->onSelect(fn (array $entry) => $this->handleFileSelected($entry))
            ->onCancel(fn () => $this->hideFileTree());

        $this->activeModalType = 'file_tree';
    }

    public function hideFileTree(): void
    {
        $this->fileTreeComponent = null;
        $this->activeModalType = null;
        $this->restoreTypedValue();
        $this->clearSavedTypedValue();
    }

    public function hasActiveFileTree(): bool
    {
        return $this->fileTreeComponent !== null;
    }

    public function getFileTreeComponent(): ?FileTree
    {
        return $this->fileTreeComponent;
    }

    protected function handleFileSelected(array $entry): void
    {
        // Override in implementing class (FileReferences trait does this)
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOAST CONVENIENCE METHODS
    // ─────────────────────────────────────────────────────────────────────────

    public function toast(string $message): static
    {
        $this->showToast($message);
        $this->render();

        return $this;
    }

    public function toastSuccess(string $message): static
    {
        return $this->toast($message);
    }

    public function toastError(string $message): static
    {
        return $this->toast($message);
    }

    public function toastWarning(string $message): static
    {
        return $this->toast($message);
    }

    public function toastInfo(string $message): static
    {
        return $this->toast($message);
    }
}
