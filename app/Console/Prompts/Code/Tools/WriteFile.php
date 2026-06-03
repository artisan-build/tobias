<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Tools;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Stream\WritableResourceStream;

final readonly class WriteFile
{
    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'write_file',
                'description' => 'Create or overwrite a file with the given content.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'The file path to write (relative to project root or absolute)',
                        ],
                        'content' => [
                            'type' => 'string',
                            'description' => 'The content to write to the file',
                        ],
                        'create_directories' => [
                            'type' => 'boolean',
                            'description' => 'Whether to create parent directories if they do not exist (default: true)',
                        ],
                    ],
                    'required' => ['path', 'content'],
                ],
            ],
        ];
    }

    public function handle(string $path, string $content, bool $createDirectories = true): PromiseInterface
    {
        $deferred = new Deferred;
        $absolutePath = $this->resolvePath($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            if (! $createDirectories) {
                $deferred->resolve($this->error("Directory does not exist: {$directory}"));

                return $deferred->promise();
            }

            if (! mkdir($directory, 0755, true)) {
                $deferred->resolve($this->error("Failed to create directory: {$directory}"));

                return $deferred->promise();
            }
        }

        $resource = fopen($absolutePath, 'w');

        if ($resource === false) {
            $deferred->resolve($this->error("Failed to open file for writing: {$path}"));

            return $deferred->promise();
        }

        $stream = new WritableResourceStream($resource, Loop::get());

        $stream->on('error', function (\Exception $e) use ($deferred, $path) {
            $deferred->resolve($this->error("Error writing file {$path}: {$e->getMessage()}"));
        });

        $stream->on('close', function () use ($deferred, $path, $content) {
            $deferred->resolve($this->success([
                'path' => $path,
                'bytes_written' => strlen($content),
                'lines_written' => substr_count($content, "\n") + 1,
            ]));
        });

        $stream->end($content);

        return $deferred->promise();
    }

    public function handleSync(string $path, string $content, bool $createDirectories = true): array
    {
        $absolutePath = $this->resolvePath($path);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            if (! $createDirectories) {
                return $this->error("Directory does not exist: {$directory}");
            }

            if (! mkdir($directory, 0755, true)) {
                return $this->error("Failed to create directory: {$directory}");
            }
        }

        $result = file_put_contents($absolutePath, $content);

        if ($result === false) {
            return $this->error("Failed to write file: {$path}");
        }

        return $this->success([
            'path' => $path,
            'bytes_written' => $result,
            'lines_written' => substr_count($content, "\n") + 1,
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
