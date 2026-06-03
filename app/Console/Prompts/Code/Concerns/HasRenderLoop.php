<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Concerns;

use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use Symfony\Component\Console\Terminal;

trait HasRenderLoop
{
    protected ?TimerInterface $streamThrottleTimer = null;

    protected bool $needsRender = false;

    protected float $lastRenderTime = 0.0;

    protected float $minRenderInterval = 0.033; // ~30fps max during streaming

    protected int $lastTerminalWidth = 0;

    protected int $lastTerminalHeight = 0;

    public function initializeRenderLoop(): void
    {
        // Get initial terminal dimensions
        [$width, $height] = $this->getTerminalDimensions();
        $this->terminalWidth = $width;
        $this->terminalHeight = $height;
        $this->lastTerminalWidth = $width;
        $this->lastTerminalHeight = $height;

        $this->updateSymfonyTerminalCache($width, $height);

        // Listen for terminal resize via SIGWINCH
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGWINCH, function () {
                $this->handleTerminalResize();
            });
        }

        // Initial render
        $this->render();
        $this->lastRenderTime = microtime(true);
    }

    protected function handleTerminalResize(): void
    {
        [$width, $height] = $this->getTerminalDimensions();
        $this->terminalWidth = $width;
        $this->terminalHeight = $height;
        $this->lastTerminalWidth = $width;
        $this->lastTerminalHeight = $height;

        $this->updateSymfonyTerminalCache($width, $height);

        // Force full re-render on resize
        $this->prevFrame = '';
        $this->render();
        $this->lastRenderTime = microtime(true);
    }

    /**
     * Request a render — called from streaming callbacks.
     * Throttled to ~30fps to avoid excessive rendering during rapid chunks.
     */
    public function requestRender(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRenderTime;

        if ($elapsed >= $this->minRenderInterval) {
            $this->render();
            $this->lastRenderTime = $now;

            return;
        }

        // Schedule a deferred render if one isn't already pending
        if ($this->streamThrottleTimer === null) {
            $remaining = $this->minRenderInterval - $elapsed;
            $this->streamThrottleTimer = Loop::addTimer($remaining, function () {
                $this->streamThrottleTimer = null;
                $this->render();
                $this->lastRenderTime = microtime(true);
            });
        }
    }

    protected function updateSymfonyTerminalCache(int $width, int $height): void
    {
        $symfonyTerminal = new \ReflectionClass(Terminal::class);

        $widthProperty = $symfonyTerminal->getProperty('width');
        $widthProperty->setValue(null, $width);

        $heightProperty = $symfonyTerminal->getProperty('height');
        $heightProperty->setValue(null, $height);
    }

    protected function getTerminalDimensions(): array
    {
        $output = @shell_exec('stty size 2>/dev/null');

        if ($output && preg_match('/(\d+)\s+(\d+)/', $output, $matches)) {
            return [(int) $matches[2], (int) $matches[1]];
        }

        return [$this->terminal()->cols(), $this->terminal()->lines()];
    }

    public function stopRenderLoop(): void
    {
        if ($this->streamThrottleTimer) {
            Loop::cancelTimer($this->streamThrottleTimer);
            $this->streamThrottleTimer = null;
        }
    }
}
