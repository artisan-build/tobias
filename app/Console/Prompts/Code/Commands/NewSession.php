<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Commands;

use App\Console\Prompts\Code\CodePrompt;

final class NewSession
{
    public string $signature = 'new';

    public string $description = 'Create a new chat session.';

    public function handle(CodePrompt $prompt): void
    {
        $prompt->createNewSession();
    }
}
