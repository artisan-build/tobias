<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Commands;

use App\Console\Prompts\Code\CodePrompt;

final class Clear
{
    public string $signature = 'clear';

    public string $description = 'Clear the current conversation history.';

    public function handle(CodePrompt $prompt): void
    {
        $prompt->clearConversation();
        $prompt->toastSuccess('Conversation cleared');
    }
}
