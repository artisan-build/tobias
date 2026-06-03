<?php

declare(strict_types=1);

namespace App\Commands;

use App\Console\Prompts\Code\CodePrompt;
use LaravelZero\Framework\Commands\Command;

class CodeCommand extends Command
{
    protected $signature = 'code';

    protected $description = 'Launch Tobias, the agentic terminal coding assistant.';

    public function handle(): int
    {
        (new CodePrompt)->prompt();

        return self::SUCCESS;
    }
}
