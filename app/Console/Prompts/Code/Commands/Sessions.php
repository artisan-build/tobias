<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Commands;

use App\Console\Prompts\Code\CodePrompt;

final class Sessions
{
    public string $signature = 'sessions';

    public string $description = 'Focus the sessions panel.';

    public function handle(CodePrompt $prompt): void
    {
        // Focus the sessions panel so the user can navigate with j/k
        $prompt->setMode('normal');

        // Use reflection-free approach: focusNextPanel cycles, so we directly set
        while ($prompt->getFocusedPanel() !== 'sessions') {
            $prompt->focusNextPanel();
        }
    }
}
