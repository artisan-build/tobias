<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Providers;

use React\Promise\PromiseInterface;

interface ProviderInterface
{
    public function getName(): string;

    public function getDefaultModel(): string;

    public function getAvailableModels(): array;

    public function getContextWindowSize(string $model): int;

    /**
     * Stream a response from the provider.
     * Returns a promise that resolves when streaming begins.
     * Callbacks are invoked for each chunk and on completion.
     *
     * @param  array  $messages  Messages in provider-agnostic format
     * @param  string  $model  Model identifier
     * @param  callable(string $chunk): void  $onChunk  Called for each text chunk
     * @param  callable(array $toolCall): void  $onToolCall  Called for tool calls
     * @param  callable(): void  $onComplete  Called when stream completes
     * @param  callable(\Throwable $e): void  $onError  Called on error
     */
    public function stream(
        array $messages,
        string $model,
        callable $onChunk,
        callable $onToolCall,
        callable $onComplete,
        callable $onError,
        array $tools = [],
    ): PromiseInterface;

    /**
     * Build the request payload for this provider.
     */
    public function buildPayload(array $messages, string $model, array $tools = []): array;

    /**
     * Get the streaming endpoint URL.
     */
    public function getStreamUrl(string $model): string;

    /**
     * Get request headers.
     */
    public function getHeaders(): array;
}
