<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Tools;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\ReadableResourceStream;
use React\Stream\WritableResourceStream;

final readonly class UpdateFile
{
    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'update_file',
                'description' => 'Update specific parts of a file using search and replace, line replacement, or insertions.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'The file path to update (relative to project root or absolute)',
                        ],
                        'operations' => [
                            'type' => 'array',
                            'description' => 'List of update operations to perform',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => [
                                        'type' => 'string',
                                        'enum' => ['replace', 'replace_lines', 'insert_after', 'insert_before', 'delete_lines'],
                                        'description' => 'The type of operation',
                                    ],
                                    'search' => [
                                        'type' => 'string',
                                        'description' => 'For replace: the text to search for',
                                    ],
                                    'replace' => [
                                        'type' => 'string',
                                        'description' => 'For replace: the replacement text',
                                    ],
                                    'start_line' => [
                                        'type' => 'integer',
                                        'description' => 'For line operations: starting line number (1-indexed)',
                                    ],
                                    'end_line' => [
                                        'type' => 'integer',
                                        'description' => 'For line operations: ending line number (inclusive)',
                                    ],
                                    'content' => [
                                        'type' => 'string',
                                        'description' => 'For insert/replace_lines: the content to insert or replace with',
                                    ],
                                    'pattern' => [
                                        'type' => 'string',
                                        'description' => 'For insert_after/insert_before: regex pattern to match',
                                    ],
                                ],
                                'required' => ['type'],
                            ],
                        ],
                    ],
                    'required' => ['path', 'operations'],
                ],
            ],
        ];
    }

    public function handle(string $path, array $operations): PromiseInterface
    {
        $deferred = new Deferred;
        $absolutePath = $this->resolvePath($path);

        if (! file_exists($absolutePath)) {
            $deferred->resolve($this->error("File not found: {$path}"));

            return $deferred->promise();
        }

        if (! is_readable($absolutePath) || ! is_writable($absolutePath)) {
            $deferred->resolve($this->error("File not readable/writable: {$path}"));

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

        $stream->on('end', function () use ($deferred, &$content, $path, $absolutePath, $operations) {
            $result = $this->applyOperations($content, $operations);

            if (! $result['success']) {
                $deferred->resolve($result);

                return;
            }

            $this->writeContent($absolutePath, $result['content'])
                ->then(function () use ($deferred, $path, $result) {
                    $deferred->resolve($this->success([
                        'path' => $path,
                        'operations_applied' => count($result['applied']),
                        'changes' => $result['applied'],
                    ]));
                })
                ->catch(function (\Exception $e) use ($deferred, $path) {
                    $deferred->resolve($this->error("Failed to write updates to {$path}: {$e->getMessage()}"));
                });
        });

        $stream->on('error', function (\Exception $e) use ($deferred, $path) {
            $deferred->resolve($this->error("Error reading file {$path}: {$e->getMessage()}"));
        });

        return $deferred->promise();
    }

    public function handleSync(string $path, array $operations): array
    {
        $absolutePath = $this->resolvePath($path);

        if (! file_exists($absolutePath)) {
            return $this->error("File not found: {$path}");
        }

        $content = file_get_contents($absolutePath);

        if ($content === false) {
            return $this->error("Failed to read file: {$path}");
        }

        $result = $this->applyOperations($content, $operations);

        if (! $result['success']) {
            return $result;
        }

        if (file_put_contents($absolutePath, $result['content']) === false) {
            return $this->error("Failed to write updates to {$path}");
        }

        return $this->success([
            'path' => $path,
            'operations_applied' => count($result['applied']),
            'changes' => $result['applied'],
        ]);
    }

    protected function applyOperations(string $content, array $operations): array
    {
        $applied = [];

        foreach ($operations as $index => $operation) {
            $type = $operation['type'] ?? null;

            $result = match ($type) {
                'replace' => $this->applyReplace($content, $operation),
                'replace_lines' => $this->applyReplaceLines($content, $operation),
                'insert_after' => $this->applyInsertAfter($content, $operation),
                'insert_before' => $this->applyInsertBefore($content, $operation),
                'delete_lines' => $this->applyDeleteLines($content, $operation),
                default => ['success' => false, 'error' => "Unknown operation type: {$type}"],
            };

            if (! $result['success']) {
                return [
                    'success' => false,
                    'error' => "Operation {$index} failed: {$result['error']}",
                ];
            }

            $content = $result['content'];
            $applied[] = [
                'type' => $type,
                'description' => $result['description'] ?? $type,
            ];
        }

        return [
            'success' => true,
            'content' => $content,
            'applied' => $applied,
        ];
    }

    protected function applyReplace(string $content, array $operation): array
    {
        $search = $operation['search'] ?? '';
        $replace = $operation['replace'] ?? '';

        if (empty($search)) {
            return ['success' => false, 'error' => 'Search string is required'];
        }

        if (! str_contains($content, $search)) {
            return ['success' => false, 'error' => 'Search string not found in file'];
        }

        $newContent = str_replace($search, $replace, $content);
        $count = substr_count($content, $search);

        return [
            'success' => true,
            'content' => $newContent,
            'description' => "Replaced {$count} occurrence(s)",
        ];
    }

    protected function applyReplaceLines(string $content, array $operation): array
    {
        $startLine = $operation['start_line'] ?? null;
        $endLine = $operation['end_line'] ?? $startLine;
        $newContent = $operation['content'] ?? '';

        if ($startLine === null) {
            return ['success' => false, 'error' => 'start_line is required'];
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($startLine < 1 || $startLine > $totalLines) {
            return ['success' => false, 'error' => "Invalid start_line: {$startLine}"];
        }

        $endLine = min($endLine, $totalLines);
        $newLines = explode("\n", $newContent);

        array_splice($lines, $startLine - 1, $endLine - $startLine + 1, $newLines);

        return [
            'success' => true,
            'content' => implode("\n", $lines),
            'description' => "Replaced lines {$startLine}-{$endLine}",
        ];
    }

    protected function applyInsertAfter(string $content, array $operation): array
    {
        $pattern = $operation['pattern'] ?? null;
        $insertContent = $operation['content'] ?? '';

        if ($pattern === null) {
            return ['success' => false, 'error' => 'pattern is required'];
        }

        $lines = explode("\n", $content);
        $inserted = false;

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line)) {
                $newLines = explode("\n", $insertContent);
                array_splice($lines, $index + 1, 0, $newLines);
                $inserted = true;

                break;
            }
        }

        if (! $inserted) {
            return ['success' => false, 'error' => 'Pattern not found in file'];
        }

        return [
            'success' => true,
            'content' => implode("\n", $lines),
            'description' => 'Inserted content after pattern match',
        ];
    }

    protected function applyInsertBefore(string $content, array $operation): array
    {
        $pattern = $operation['pattern'] ?? null;
        $insertContent = $operation['content'] ?? '';

        if ($pattern === null) {
            return ['success' => false, 'error' => 'pattern is required'];
        }

        $lines = explode("\n", $content);
        $inserted = false;

        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line)) {
                $newLines = explode("\n", $insertContent);
                array_splice($lines, $index, 0, $newLines);
                $inserted = true;

                break;
            }
        }

        if (! $inserted) {
            return ['success' => false, 'error' => 'Pattern not found in file'];
        }

        return [
            'success' => true,
            'content' => implode("\n", $lines),
            'description' => 'Inserted content before pattern match',
        ];
    }

    protected function applyDeleteLines(string $content, array $operation): array
    {
        $startLine = $operation['start_line'] ?? null;
        $endLine = $operation['end_line'] ?? $startLine;

        if ($startLine === null) {
            return ['success' => false, 'error' => 'start_line is required'];
        }

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($startLine < 1 || $startLine > $totalLines) {
            return ['success' => false, 'error' => "Invalid start_line: {$startLine}"];
        }

        $endLine = min($endLine, $totalLines);
        $deletedCount = $endLine - $startLine + 1;

        array_splice($lines, $startLine - 1, $deletedCount);

        return [
            'success' => true,
            'content' => implode("\n", $lines),
            'description' => "Deleted {$deletedCount} line(s)",
        ];
    }

    protected function writeContent(string $path, string $content): PromiseInterface
    {
        $deferred = new Deferred;

        $resource = fopen($path, 'w');

        if ($resource === false) {
            $deferred->reject(new \Exception('Failed to open file for writing'));

            return $deferred->promise();
        }

        $stream = new WritableResourceStream($resource, Loop::get());

        $stream->on('error', function (\Exception $e) use ($deferred) {
            $deferred->reject($e);
        });

        $stream->on('close', function () use ($deferred) {
            $deferred->resolve(true);
        });

        $stream->end($content);

        return $deferred->promise();
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
