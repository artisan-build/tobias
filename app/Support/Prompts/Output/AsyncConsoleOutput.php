<?php

/*
 * Vendored from artisan-build/community-prompts (ArtisanBuild\CommunityPrompts\Output\AsyncConsoleOutput).
 * See App\Support\Prompts\AsyncPrompt for why this is carried locally.
 */

namespace App\Support\Prompts\Output;

use Laravel\Prompts\Output\ConsoleOutput;
use Override;
use React\Stream\WritableResourceStream;

class AsyncConsoleOutput extends ConsoleOutput
{
    protected WritableResourceStream $stdout;

    /**
     * Write to the output buffer.
     */
    #[Override]
    protected function doWrite(string $message, bool $newline): void
    {
        if ($newline) {
            $message .= PHP_EOL;
        }

        $this->stdout ??= new WritableResourceStream(STDOUT);
        $this->stdout->write($message);

        $trailingNewLines = strlen($message) - strlen(rtrim($message, PHP_EOL));

        if (trim($message) === '') {
            $this->newLinesWritten += $trailingNewLines;
        } else {
            $this->newLinesWritten = $trailingNewLines;
        }
    }

    /**
     * Write output directly, bypassing newline capture.
     */
    #[Override]
    public function writeDirectly(string $message): void
    {
        $this->doWrite($message, false);
    }
}
