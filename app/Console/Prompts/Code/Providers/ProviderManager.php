<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Providers;

use InvalidArgumentException;

class ProviderManager
{
    /** @var array<string, ProviderInterface> */
    protected array $providers = [];

    protected ?string $currentProvider = null;

    protected ?string $currentModel = null;

    public function __construct()
    {
        $this->registerDefaultProviders();
    }

    protected function registerDefaultProviders(): void
    {
        $this->register(new OpenAIProvider);
        $this->register(new GeminiProvider);
        $this->register(new AnthropicProvider);
        $this->register(new LMStudioProvider);
    }

    public function register(ProviderInterface $provider): static
    {
        $this->providers[$provider->getName()] = $provider;

        return $this;
    }

    public function use(string $provider, ?string $model = null): static
    {
        if (! isset($this->providers[$provider])) {
            throw new InvalidArgumentException("Unknown provider: {$provider}");
        }

        $this->currentProvider = $provider;
        $this->currentModel = $model ?? $this->providers[$provider]->getDefaultModel();

        return $this;
    }

    public function getProvider(?string $name = null): ProviderInterface
    {
        $name = $name ?? $this->currentProvider ?? 'gemini';

        if (! isset($this->providers[$name])) {
            throw new InvalidArgumentException("Unknown provider: {$name}");
        }

        return $this->providers[$name];
    }

    public function getCurrentProvider(): ?string
    {
        return $this->currentProvider;
    }

    public function getModel(): string
    {
        if ($this->currentModel) {
            return $this->currentModel;
        }

        return $this->getProvider()->getDefaultModel();
    }

    public function getAvailableProviders(): array
    {
        return array_keys($this->providers);
    }

    public function getProviderModels(string $provider): array
    {
        return $this->getProvider($provider)->getAvailableModels();
    }

    public function getContextWindowSize(): int
    {
        return $this->getProvider()->getContextWindowSize($this->getModel());
    }

    /**
     * Stream a response using the current provider.
     */
    public function stream(
        array $messages,
        callable $onChunk,
        callable $onToolCall,
        callable $onComplete,
        callable $onError,
        array $tools = [],
    ): void {
        $provider = $this->getProvider();
        $model = $this->getModel();

        $provider->stream(
            $messages,
            $model,
            $onChunk,
            $onToolCall,
            $onComplete,
            $onError,
            $tools
        );
    }
}
