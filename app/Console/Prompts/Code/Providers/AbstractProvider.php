<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Providers;

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

abstract class AbstractProvider implements ProviderInterface
{
    protected Browser $browser;

    public function __construct()
    {
        $this->browser = new Browser;
    }

    public function stream(
        array $messages,
        string $model,
        callable $onChunk,
        callable $onToolCall,
        callable $onComplete,
        callable $onError,
        array $tools = [],
    ): PromiseInterface {
        $url = $this->getStreamUrl($model);
        $headers = $this->getHeaders();
        $payload = $this->buildPayload($messages, $model, $tools);

        return $this->browser->requestStreaming('POST', $url, $headers, json_encode($payload))
            ->then(
                function (ResponseInterface $response) use ($onChunk, $onToolCall, $onComplete, $onError) {
                    $statusCode = $response->getStatusCode();

                    if ($statusCode >= 400) {
                        /** @var ReadableStreamInterface $body */
                        $body = $response->getBody();
                        $errorBody = '';

                        $body->on('data', function (string $data) use (&$errorBody) {
                            $errorBody .= $data;
                        });

                        $body->on('close', function () use ($statusCode, &$errorBody, $onError) {
                            $decoded = json_decode($errorBody, true);
                            $message = $decoded['error']['message'] ?? $errorBody;
                            $onError(new \RuntimeException("HTTP {$statusCode}: {$message}"));
                        });

                        return;
                    }

                    /** @var ReadableStreamInterface $body */
                    $body = $response->getBody();
                    $buffer = '';

                    $body->on('data', function (string $data) use (&$buffer, $onChunk, $onToolCall) {
                        $buffer .= $data;
                        $this->processBuffer($buffer, $onChunk, $onToolCall);
                    });

                    $body->on('error', fn (\Exception $e) => $onError($e));
                    $body->on('close', fn () => $onComplete());
                },
                fn (\Exception $e) => $onError($e)
            );
    }

    /**
     * Process the buffer and extract complete SSE events.
     */
    protected function processBuffer(string &$buffer, callable $onChunk, callable $onToolCall): void
    {
        // Split on double newlines (SSE event boundaries)
        while (($pos = strpos($buffer, "\n\n")) !== false) {
            $event = substr($buffer, 0, $pos);
            $buffer = substr($buffer, $pos + 2);

            $this->processEvent($event, $onChunk, $onToolCall);
        }

        // Also handle single newline separated data lines
        $lines = explode("\n", $buffer);
        $buffer = array_pop($lines); // Keep incomplete line in buffer

        foreach ($lines as $line) {
            if (str_starts_with($line, 'data: ')) {
                $this->processDataLine(substr($line, 6), $onChunk, $onToolCall);
            }
        }
    }

    /**
     * Process a complete SSE event.
     */
    protected function processEvent(string $event, callable $onChunk, callable $onToolCall): void
    {
        foreach (explode("\n", $event) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $this->processDataLine(substr($line, 6), $onChunk, $onToolCall);
            }
        }
    }

    /**
     * Process a data line from SSE. Provider-specific parsing.
     */
    abstract protected function processDataLine(string $data, callable $onChunk, callable $onToolCall): void;
}
