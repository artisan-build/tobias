<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Commands;

use App\Console\Prompts\Code\CodePrompt;

final class Save
{
    public string $signature = 'save';

    public string $description = 'Save the current session for later resumption.';

    public function handle(CodePrompt $prompt): void
    {
        if ($prompt->saveSession()) {
            $prompt->toastSuccess('Session saved');
        } else {
            $prompt->toastError('Failed to save session');
        }
    }
}
