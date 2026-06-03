<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Commands;

use App\Console\Prompts\Code\CodePrompt;

final class Quit
{
    public string $signature = 'quit';

    public string $description = 'Exit the prompt and return to the terminal.';

    public function handle(CodePrompt $prompt): void
    {
        $prompt->terminate();
    }
}
