<?php

declare(strict_types=1);

namespace App\Console\Prompts\Code\Tools;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

final readonly class ListFiles
{
    public static function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'list_files',
                'description' => 'List files and directories in a given path. Returns file names, types (file/directory), and sizes. Use this to explore project structure.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'The directory path to list (relative to project root or absolute). Defaults to current directory if not specified.',
                        ],
                        'show_hidden' => [
                            'type' => 'boolean',
                            'description' => 'Whether to include hidden files (dot files like .git, .env). Defaults to false.',
                        ],
                        'recursive' => [
                            'type' => 'boolean',
                            'description' => 'Whether to list files recursively in subdirectories. Defaults to false. Use with caution on large directories.',
                        ],
                        'max_depth' => [
                            'type' => 'integer',
                            'description' => 'Maximum depth for recursive listing (1 = immediate children only). Only applies when recursive is true. Defaults to 3.',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    public function handle(
        ?string $path = null,
        bool $showHidden = false,
        bool $recursive = false,
        int $maxDepth = 3
    ): PromiseInterface {
        $deferred = new Deferred;

        // Use event loop to make it non-blocking
        Loop::futureTick(function () use ($deferred, $path, $showHidden, $recursive, $maxDepth) {
            $result = $this->handleSync($path, $showHidden, $recursive, $maxDepth);
            $deferred->resolve($result);
        });

        return $deferred->promise();
    }

    public function handleSync(
        ?string $path = null,
        bool $showHidden = false,
        bool $recursive = false,
        int $maxDepth = 3
    ): array {
        $absolutePath = $this->resolvePath($path ?? '.');

        if (! is_dir($absolutePath)) {
            return $this->error('Directory not found: '.($path ?? '.'));
        }

        if (! is_readable($absolutePath)) {
            return $this->error('Directory not readable: '.($path ?? '.'));
        }

        $entries = $this->scanDirectory($absolutePath, $showHidden, $recursive, $maxDepth, 0);

        return $this->success([
            'path' => $path ?? '.',
            'absolute_path' => $absolutePath,
            'show_hidden' => $showHidden,
            'recursive' => $recursive,
            'total_entries' => count($entries),
            'entries' => $entries,
        ]);
    }

    protected function scanDirectory(
        string $path,
        bool $showHidden,
        bool $recursive,
        int $maxDepth,
        int $currentDepth
    ): array {
        $entries = [];
        $items = scandir($path);

        if ($items === false) {
            return $entries;
        }

        foreach ($items as $item) {
            // Skip . and ..
            if ($item === '.' || $item === '..') {
                continue;
            }

            // Skip hidden files unless requested
            if (! $showHidden && str_starts_with($item, '.')) {
                continue;
            }

            $fullPath = $path.DIRECTORY_SEPARATOR.$item;
            $isDir = is_dir($fullPath);

            $entry = [
                'name' => $item,
                'type' => $isDir ? 'directory' : 'file',
                'path' => $this->relativePath($fullPath),
            ];

            if (! $isDir) {
                $size = filesize($fullPath);
                $entry['size'] = $size !== false ? $size : 0;
                $entry['size_human'] = $this->formatSize($size ?: 0);
                $entry['extension'] = pathinfo($item, PATHINFO_EXTENSION) ?: null;
            }

            // Add children for directories if recursive
            if ($isDir && $recursive && $currentDepth < $maxDepth) {
                $children = $this->scanDirectory($fullPath, $showHidden, $recursive, $maxDepth, $currentDepth + 1);
                if (! empty($children)) {
                    $entry['children'] = $children;
                    $entry['child_count'] = count($children);
                }
            } elseif ($isDir) {
                // Just count immediate children for non-recursive
                $childItems = @scandir($fullPath);
                if ($childItems !== false) {
                    $count = count(array_filter($childItems, fn ($i) => $i !== '.' && $i !== '..'));
                    $entry['child_count'] = $count;
                }
            }

            $entries[] = $entry;
        }

        // Sort: directories first, then files, alphabetically
        usort($entries, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    protected function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return getcwd().'/'.$path;
    }

    protected function relativePath(string $absolutePath): string
    {
        $cwd = getcwd();

        if (str_starts_with($absolutePath, $cwd)) {
            $relative = substr($absolutePath, strlen($cwd) + 1);

            return $relative ?: '.';
        }

        return $absolutePath;
    }

    protected function formatSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 1).' '.$units[$unitIndex];
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
