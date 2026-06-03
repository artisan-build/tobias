<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Commands;

use App\Console\Prompts\Code\CodePrompt;

final class Help
{
    public string $signature = 'help';

    public string $description = 'Show available commands and key bindings.';

    public function handle(CodePrompt $prompt): void
    {
        $prompt->openModal('help');
    }
}
