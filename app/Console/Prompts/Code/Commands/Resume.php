<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Commands;

use App\Console\Prompts\Code\CodePrompt;
use App\Console\Prompts\Code\Support\Session;

final class Resume
{
    public string $signature = 'resume';

    public string $description = 'Resume a previous code editor chat session.';

    public function handle(CodePrompt $prompt): void
    {
        $sessions = Session::list();

        if ($sessions->isEmpty()) {
            $prompt->toastInfo('No saved sessions found');

            return;
        }

        $sessionData = $sessions
            ->take(10)
            ->map(fn (array $session) => [
                'id' => $session['id'],
                'label' => $this->formatSessionLabel($session),
                'description' => "{$session['messageCount']} messages",
            ])
            ->values()
            ->all();

        $prompt->openModal('resume', [
            'sessions' => $sessionData,
            'selectedIndex' => 0,
        ]);
    }

    protected function formatSessionLabel(array $session): string
    {
        $date = $session['savedAt']
            ? now()->parse($session['savedAt'])->diffForHumans()
            : 'Unknown';

        return substr($session['id'], 0, 8)." ({$date})";
    }
}
