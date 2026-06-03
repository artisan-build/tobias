<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Tools;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableResourceStream;

final readonly class ReadFile
{
    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'read_file',
                'description' => 'Read the contents of a file. Supports reading specific line ranges.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'The file path to read (relative to project root or absolute)',
                        ],
                        'start_line' => [
                            'type' => 'integer',
                            'description' => 'Optional: Starting line number (1-indexed)',
                        ],
                        'end_line' => [
                            'type' => 'integer',
                            'description' => 'Optional: Ending line number (inclusive)',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
        ];
    }

    public function handle(string $path, ?int $startLine = null, ?int $endLine = null): PromiseInterface
    {
        $deferred = new Deferred;
        $absolutePath = $this->resolvePath($path);

        if (! file_exists($absolutePath)) {
            $deferred->resolve($this->error("File not found: {$path}"));

            return $deferred->promise();
        }

        if (! is_readable($absolutePath)) {
            $deferred->resolve($this->error("File not readable: {$path}"));

            return $deferred->promise();
        }

        $resource = fopen($absolutePath, 'r');

        if ($resource === false) {
            $deferred->resolve($this->error("Failed to open file: {$path}"));

            return $deferred->promise();
        }

        $stream = new ReadableResourceStream($resource, Loop::get());
        $content = '';

        $stream->on('data', function (string $chunk) use (&$content) {
            $content .= $chunk;
        });

        $stream->on('end', function () use ($deferred, &$content, $path, $startLine, $endLine) {
            $result = $this->processContent($content, $path, $startLine, $endLine);
            $deferred->resolve($result);
        });

        $stream->on('error', function (\Exception $e) use ($deferred, $path) {
            $deferred->resolve($this->error("Error reading file {$path}: {$e->getMessage()}"));
        });

        return $deferred->promise();
    }

    public function handleSync(string $path, ?int $startLine = null, ?int $endLine = null): array
    {
        $absolutePath = $this->resolvePath($path);

        if (! file_exists($absolutePath)) {
            return $this->error("File not found: {$path}");
        }

        if (! is_readable($absolutePath)) {
            return $this->error("File not readable: {$path}");
        }

        $content = file_get_contents($absolutePath);

        if ($content === false) {
            return $this->error("Failed to read file: {$path}");
        }

        return $this->processContent($content, $path, $startLine, $endLine);
    }

    protected function processContent(string $content, string $path, ?int $startLine, ?int $endLine): array
    {
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($startLine !== null) {
            $startLine = max(1, $startLine);
            $endLine = $endLine ?? $startLine;
            $endLine = min($totalLines, $endLine);

            $selectedLines = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
            $content = implode("\n", $selectedLines);

            return $this->success([
                'path' => $path,
                'content' => $content,
                'lines' => [
                    'start' => $startLine,
                    'end' => $endLine,
                    'total' => $totalLines,
                ],
            ]);
        }

        return $this->success([
            'path' => $path,
            'content' => $content,
            'lines' => [
                'total' => $totalLines,
            ],
        ]);
    }

    protected function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return getcwd().'/'.$path;
    }

    protected function success(array $data): array
    {
        return [
            'success' => true,
            ...$data,
        ];
    }

    protected function error(string $message): array
    {
        return [
            'success' => false,
            'error' => $message,
        ];
    }
}
