<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Concerns;

use App\Console\Prompts\Code\Support\FileContext;
use Illuminate\Support\Collection;

trait FileReferences
{
    protected ?string $pendingFileReference = null;

    public function initializeFileReferences(): void
    {
        // File contexts are now per-session on ChatSession.
        // This method is kept for compatibility with the constructor call chain.
    }

    public function detectFileReference(string $input): ?string
    {
        if (preg_match('/(?:^|\s)[~@](\S*)$/', $input, $matches)) {
            return $matches[1] ?? '';
        }

        return null;
    }

    public function isTypingFileReference(string $input): bool
    {
        return preg_match('/(?:^|\s)[~@]\S*$/', $input) === 1;
    }

    public function getFileReferenceQuery(string $input): string
    {
        if (preg_match('/(?:^|\s)[~@](\S*)$/', $input, $matches)) {
            return $matches[1] ?? '';
        }

        return '';
    }

    public function handleFileReferenceInput(string $char): void
    {
        if ($char === '~' || $char === '@') {
            $this->pendingFileReference = '';
            $this->openFileTreeForReference();
        }
    }

    public function openFileTreeForReference(): void
    {
        $this->showFileTree(getcwd());
        $this->getFileTreeComponent()?->onSelect(fn (array $entry) => $this->completeFileReference($entry));
    }

    public function completeFileReference(array $entry): void
    {
        if ($entry['type'] !== 'file') {
            return;
        }

        $context = new FileContext(
            path: $entry['path'],
            basePath: getcwd()
        );

        $this->addFileContext($context);
        $this->hideFileTree();
        $this->pendingFileReference = null;
        $this->toastSuccess("Added: {$context->relativePath}");
        $this->render();
    }

    public function addFileContext(FileContext $context): static
    {
        if (! $context->exists()) {
            $this->toastError("File not found: {$context->relativePath}");

            return $this;
        }

        $fileContexts = $this->getFileContexts();

        // Avoid duplicates
        $existing = $fileContexts->first(
            fn (FileContext $c) => $c->path === $context->path
                && $c->startLine === $context->startLine
                && $c->endLine === $context->endLine
        );

        if (! $existing) {
            $fileContexts->push($context);
        }

        return $this;
    }

    public function addFileContextFromReference(string $reference): static
    {
        $context = FileContext::fromReference($reference, getcwd());

        return $this->addFileContext($context);
    }

    public function removeFileContext(int $index): static
    {
        $fileContexts = $this->getFileContexts();
        $fileContexts->forget($index);

        // Re-key the collection
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->fileContexts = $fileContexts->values();
        }

        return $this;
    }

    public function clearFileContexts(): static
    {
        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->fileContexts = collect();
        }

        return $this;
    }

    public function getFileContexts(): Collection
    {
        return $this->sessionManager->active()?->fileContexts ?? collect();
    }

    public function hasFileContexts(): bool
    {
        return $this->getFileContexts()->isNotEmpty();
    }

    public function getFileContextMessages(): array
    {
        return $this->getFileContexts()
            ->map(fn (FileContext $context) => $context->toContextMessage())
            ->all();
    }

    public function getFileContextDisplayLines(): Collection
    {
        return $this->getFileContexts()->map(
            fn (FileContext $context, int $index) => [
                'index' => $index,
                'display' => $context->toDisplayString(40),
                'compact' => $context->toCompactString(),
                'path' => $context->relativePath,
            ]
        );
    }

    public function parseAndAddFileReferences(string $input): string
    {
        $pattern = '/[~@]([^\s]+)/';

        $input = preg_replace_callback($pattern, function ($matches) {
            $reference = $matches[1];
            $context = FileContext::fromReference($reference, getcwd());

            if ($context->exists()) {
                $this->addFileContext($context);
            }

            return '';
        }, $input);

        return trim(preg_replace('/\s+/', ' ', $input));
    }

    public function promptForLineRange(FileContext $context): void
    {
        // This would show a modal for specifying line range
    }
}
