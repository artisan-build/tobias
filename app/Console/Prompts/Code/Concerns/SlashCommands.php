<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Concerns;

use App\Console\Prompts\Code\Commands\Clear;
use App\Console\Prompts\Code\Commands\Context;
use App\Console\Prompts\Code\Commands\Help;
use App\Console\Prompts\Code\Commands\NewSession;
use App\Console\Prompts\Code\Commands\Quit;
use App\Console\Prompts\Code\Commands\Resume;
use App\Console\Prompts\Code\Commands\Save;
use App\Console\Prompts\Code\Commands\Sessions;
use Illuminate\Support\Collection;

trait SlashCommands
{
    protected Collection $commands;

    protected ?string $slashCommandBuffer = null;

    protected bool $isTypingSlashCommand = false;

    public function initializeSlashCommands(): void
    {
        $this->commands = collect();
    }

    public function registerSlashCommands(): void
    {
        $this->initializeSlashCommands();

        $this->registerCommand(new Resume)
            ->registerCommand(new Clear)
            ->registerCommand(new Save)
            ->registerCommand(new Help)
            ->registerCommand(new Context)
            ->registerCommand(new NewSession)
            ->registerCommand(new Sessions)
            ->registerCommand(new Quit);
    }

    public function registerCommand(object $command): static
    {
        $this->commands->put($command->signature, [
            'signature' => $command->signature,
            'description' => $command->description,
            'handler' => $command,
        ]);

        return $this;
    }

    public function getCommands(): Collection
    {
        return $this->commands;
    }

    public function findCommand(string $signature): ?array
    {
        return $this->commands->get($signature);
    }

    public function isTypingSlashCommand(string $input): bool
    {
        return str_starts_with(trim($input), '/');
    }

    public function detectSlashCommand(string $input): ?string
    {
        if (preg_match('/^\/(\S*)$/', trim($input), $matches)) {
            return $matches[1] ?? '';
        }

        return null;
    }

    public function getMatchingCommands(string $query): Collection
    {
        if (empty($query)) {
            return $this->commands->values();
        }

        $query = strtolower($query);

        return $this->commands
            ->filter(fn (array $cmd) => str_contains(strtolower($cmd['signature']), $query))
            ->values();
    }

    public function showSlashCommandModal(string $query = ''): void
    {
        $this->slashCommandBuffer = $query;
        $this->isTypingSlashCommand = true;

        $commands = $this->getMatchingCommands($query)->map(fn (array $cmd) => [
            'value' => $cmd['signature'],
            'label' => "/{$cmd['signature']} - {$cmd['description']}",
        ])->values()->all();

        $this->openModal('command_palette', [
            'commands' => $commands,
            'selectedIndex' => 0,
        ]);
    }

    public function hideSlashCommandModal(): void
    {
        $this->closeModal();
        $this->slashCommandBuffer = null;
        $this->isTypingSlashCommand = false;
    }

    public function selectSlashCommand(string $signature): void
    {
        $this->hideSlashCommandModal();

        $chat = $this->sessionManager->active();
        if ($chat) {
            $chat->typedValue = '';
            $chat->cursorPosition = 0;
        }

        $this->executeSlashCommand($signature);
    }

    public function executeSlashCommand(string $signature): void
    {
        $command = $this->findCommand($signature);

        if (! $command) {
            $this->toastError("Unknown command: /{$signature}");

            return;
        }

        $handler = $command['handler'];

        if (method_exists($handler, 'handle')) {
            $handler->handle($this);
        }
    }

    public function completeSlashCommand(): void
    {
        $chat = $this->sessionManager->active();
        if (! $chat) {
            return;
        }

        $query = $chat->typedValue;
        if (str_starts_with($query, '/')) {
            $query = substr($query, 1);
        }

        $matches = $this->getMatchingCommands($query);

        if ($matches->count() === 1) {
            $chat->typedValue = '/'.$matches->first()['signature'];
            $chat->cursorPosition = mb_strlen($chat->typedValue);
        }
    }
}
