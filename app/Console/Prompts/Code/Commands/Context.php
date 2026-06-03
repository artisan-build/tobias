<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Commands;

use App\Console\Prompts\Code\CodePrompt;

final class Context
{
    public string $signature = 'context';

    public string $description = 'View and manage file contexts.';

    public function handle(CodePrompt $prompt): void
    {
        $contexts = $prompt->getFileContexts();

        if ($contexts->isEmpty()) {
            $prompt->showFileTree();

            return;
        }

        $options = $contexts
            ->map(fn ($context, $index) => [
                'value' => (string) $index,
                'label' => $context->relativePath,
                'description' => $context->hasLineRange()
                    ? "Lines {$context->startLine}-{$context->endLine}"
                    : "{$context->totalLines} lines",
            ])
            ->push([
                'value' => 'add',
                'label' => '+ Add file',
                'description' => 'Browse and add a new file',
            ])
            ->push([
                'value' => 'clear',
                'label' => 'x Clear all',
                'description' => 'Remove all file contexts',
            ])
            ->values()
            ->all();

        $prompt->openModal('context', [
            'options' => $options,
            'selectedIndex' => 0,
        ]);
    }
}
